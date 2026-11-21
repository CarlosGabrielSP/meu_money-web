<?php

use App\Actions\Cartoes\PagarFatura;
use App\Enums\StatusMembro;
use App\Models\Carteira;
use App\Models\CarteiraMembro;
use App\Models\Contato;
use App\Models\Fatura;
use App\Models\Lancamento;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\RecebimentoContato;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Scenario of the card mirror (A, B, wallet X and the Nubank linked to X, December invoice with the
 * shared Restaurante 200,00 and A's Mercado 100,00), plus Ana, a contact of A and member of X.
 */
beforeEach(function () {
    cenarioEspelho();
    $this->ana = User::factory()->create(['name' => 'Ana']);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->user->id, 'convidado_id' => $this->ana->id]);
    membroDaCarteira($this->x, $this->ana);
    $this->outra = Carteira::factory()->for($this->user)->create(['nome' => 'W']);
});

function membroDaCarteira(Carteira $carteira, User $membro): void
{
    CarteiraMembro::factory()->create([
        'carteira_id' => $carteira->id,
        'user_id' => $membro->id,
        'status' => StatusMembro::Aceito,
        'respondido_em' => now(),
    ]);
}

/**
 * The invoice row of the card in the wallet, as the user sees it in December.
 *
 * @return array<string, mixed>
 */
function linhaDaFatura(User $user, Carteira $carteira): array
{
    $linha = [];
    test()->actingAs($user)->get(route('home', ['carteira' => $carteira->id, 'mes' => '2026-12']))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$linha) {
            $linha = $page->toArray()['props']['faturasVinculadas'][0];
        });

    return $linha;
}

function pagamentoDe(Fatura $fatura, Carteira $carteira, int $valor): PagamentoFatura
{
    return $fatura->pagamentos()->create(['carteira_id' => $carteira->id, 'valor' => $valor, 'data' => '2026-11-10']);
}

it('CA-208.1 gives the member the invoice with only the payments from the delegated wallet', function () {
    pagarComo($this->ana, $this->nubank, '2026-12', '50,00')->assertSessionHasNoErrors();
    pagarComo($this->user, $this->nubank, '2026-12', '30,00')->assertSessionHasNoErrors();
    pagamentoDe(faturaDe($this->nubank, '2026-12'), $this->outra, 1000);

    $linha = linhaDaFatura($this->ana, $this->x);

    expect($linha['faturaDoMes'])->referencia->toBe('2026-12')->aPagar->toBe(21000)->aPagarAutor->toBe(11000)
        ->and(collect($linha['faturaDoMes']['pagamentosLista'])->map(fn (array $pagamento) => [$pagamento['valor'], $pagamento['pagoPor']])->all())
        ->toBe([[5000, 'Ana'], [3000, null]])
        ->and(collect($linha['faturaDoMes']['partes'])->where('voce', false)->pluck('aberto', 'userId')->all())->toBe([$this->b->id => 10000]);
});

it('CA-208.1 keeps the owner row without member actions and refuses the card to the member', function () {
    expect(linhaDaFatura($this->user, $this->x)['faturaDoMes'])->toBeNull();

    $this->actingAs($this->ana)->get(route('home', ['carteira' => $this->nubank->id]))->assertForbidden();
});

it('CA-208.2 pays from the linked wallet as the owner and stores who paid', function () {
    pagarComo($this->ana, $this->nubank, '2026-12', '100,00', [$this->b->id])->assertSessionHasNoErrors();

    $pagamento = PagamentoFatura::query()->sole();
    expect($pagamento)->valor->toBe(20000)->carteira_id->toBe($this->x->id)->pago_por_id->toBe($this->ana->id)->pago_por_nome->toBe('Ana')
        ->and(quitacoesDeB())->toBe([['2026-12', 'autor', 10000]]);
});

it('CA-208.2 stores nothing as who paid when the owner pays', function () {
    pagarComo($this->user, $this->nubank, '2026-12', '100,00')->assertSessionHasNoErrors();

    expect(PagamentoFatura::query()->sole())->pago_por_id->toBeNull()->pago_por_nome->toBeNull();
});

it('CA-208.6 refuses the member a missing invoice, an invoice of another wallet and the invoice dates', function () {
    pagarComo($this->ana, $this->nubank, '2027-03', '10,00')->assertForbidden();

    $outroCartao = cartaoDe($this->user, 'Inter', $this->outra);
    compraNoCartao('2026-11-05', 5000, $this->lazer, cartao: $outroCartao);
    pagarComo($this->ana, $outroCartao, '2026-12', '10,00')->assertForbidden();

    $this->actingAs($this->ana)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-29', 'vencimento' => '2026-12-09'])
        ->assertForbidden();
    expect(PagamentoFatura::query()->count())->toBe(0);
});

it('CA-208.6 refuses the member while the owner is deactivated', function () {
    $this->user->forceFill(['desativado_em' => now()])->save();

    pagarComo($this->ana, $this->nubank, '2026-12', '10,00')->assertForbidden();
});

it('CA-208.3 deletes any listed payment with the effects of the owner', function () {
    pagarComo($this->user, $this->nubank, '2026-12', '100,00', [$this->b->id])->assertSessionHasNoErrors();

    $this->actingAs($this->ana)->delete(route('faturas.pagamentos.destroy', PagamentoFatura::query()->sole()))
        ->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.message', 'Pagamento excluído.');

    expect(PagamentoFatura::query()->count())->toBe(0)->and(QuitacaoContato::query()->count())->toBe(0);
});

it('CA-208.7 refuses deleting a payment from a wallet not delegated to the member', function () {
    $pagamento = pagamentoDe(faturaDe($this->nubank, '2026-12'), $this->outra, 1000);

    $this->actingAs($this->ana)->delete(route('faturas.pagamentos.destroy', $pagamento))->assertForbidden();
    expect($pagamento->fresh())->not->toBeNull();
});

it('CA-208.4 marks and unmarks the "a receber" item as the owner', function () {
    pagarComo($this->user, $this->nubank, '2026-12', '100,00', [$this->b->id])->assertSessionHasNoErrors();
    $quitacao = QuitacaoContato::query()->sole();

    $this->actingAs($this->ana)->patch(route('a-receber.recebido', $quitacao), ['recebido' => true])->assertRedirect();
    expect(RecebimentoContato::query()->sole())->valor->toBe(10000)->pagamento_fatura_id->toBeNull();

    $this->actingAs($this->ana)->patch(route('a-receber.recebido', $quitacao), ['recebido' => false])->assertRedirect();
    expect(RecebimentoContato::query()->count())->toBe(0);
});

it('CA-208.8 refuses the "a receber" item of a payment from a wallet not delegated to the member', function () {
    $pagamento = pagamentoDe(faturaDe($this->nubank, '2026-12'), $this->outra, 10000);
    $quitacao = QuitacaoContato::query()->create([
        'fatura_id' => $pagamento->fatura_id,
        'contato_id' => $this->b->id,
        'valor' => 10000,
        'origem' => 'autor',
        'pagamento_fatura_id' => $pagamento->id,
    ]);

    $this->actingAs($this->ana)->patch(route('a-receber.recebido', $quitacao), ['recebido' => true])->assertForbidden();
});

it('CA-208.5 lets the member of the contact wallet pay and delete on the owner mirror', function () {
    $bruno = User::factory()->create(['name' => 'Bruno']);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->b->id, 'convidado_id' => $bruno->id]);
    membroDaCarteira($this->z, $bruno);
    $espelho = espelhoDe($this->nubank);

    $linha = linhaDaFatura($bruno, $this->z);
    expect($linha)->carteiraId->toBe($espelho->id)
        ->and($linha['faturaDoMes'])->aPagar->toBe(10000)
        ->and(collect($linha['faturaDoMes']['partes'])->where('voce', false)->all())->toBe([]);

    pagarComo($bruno, $espelho, '2026-12', '100,00')->assertSessionHasNoErrors();
    $pagamento = PagamentoFatura::query()->sole();
    expect($pagamento)->carteira_id->toBe($this->z->id)->pago_por_nome->toBe('Bruno')
        ->and(quitacoesDeB())->toBe([['2026-12', 'contato', 10000]]);

    $this->actingAs($bruno)->delete(route('faturas.pagamentos.destroy', $pagamento))->assertRedirect();
    expect(quitacoesDeB())->toBe([]);
});

it('CA-208.9 names the member who paid on the owner card screen', function () {
    pagarComo($this->ana, $this->nubank, '2026-12', '50,00')->assertSessionHasNoErrors();
    pagarComo($this->user, $this->nubank, '2026-12', '30,00')->assertSessionHasNoErrors();

    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.pagamentosLista.0.pagoPor', 'Ana')
        ->where('fatura.pagamentosLista.1.pagoPor', null));
});

it('CA-208.10 shows who paid to another member and keeps the name after the account is deleted', function () {
    $carla = User::factory()->create(['name' => 'Carla']);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->user->id, 'convidado_id' => $carla->id]);
    membroDaCarteira($this->x, $carla);
    pagarComo($this->ana, $this->nubank, '2026-12', '50,00')->assertSessionHasNoErrors();

    expect(linhaDaFatura($carla, $this->x)['faturaDoMes']['pagamentosLista'][0]['pagoPor'])->toBe('Ana');

    $this->ana->delete();

    expect(PagamentoFatura::query()->sole())->pago_por_id->toBeNull()->pago_por_nome->toBe('Ana')
        ->and(linhaDaFatura($carla, $this->x)['faturaDoMes']['pagamentosLista'][0]['pagoPor'])->toBe('Ana');
});

it('CA-208.11 lets the member settle and receive the own part of the owner invoice', function () {
    $this->actingAs($this->user)->post(route('lancamentos.store'), dadosDeA([
        'descricao' => 'Cinema',
        'valor' => '80,00',
        'data' => '2026-11-06',
        'rateio' => ['modo' => 'iguais', 'participantes' => [['user_id' => $this->user->id], ['user_id' => $this->ana->id]]],
    ]))->assertSessionHasNoErrors();

    expect(collect(linhaDaFatura($this->ana, $this->x)['faturaDoMes']['partes'])->where('voce', false)->pluck('aberto', 'userId')->sortKeys()->all())
        ->toBe(collect([$this->b->id => 10000, $this->ana->id => 4000])->sortKeys()->all());

    pagarComo($this->ana, $this->nubank, '2026-12', '', [$this->ana->id])->assertSessionHasNoErrors();
    $quitacao = QuitacaoContato::query()->where('contato_id', $this->ana->id)->sole();
    expect($quitacao)->valor->toBe(4000);

    $this->actingAs($this->ana)->patch(route('a-receber.recebido', $quitacao), ['recebido' => true])->assertRedirect();
    expect(RecebimentoContato::query()->sole()->valor)->toBe(4000);
});

it('CA-208.12 records no change on entries when the member receives or deletes a payment', function () {
    pagarComo($this->user, $this->nubank, '2026-12', '100,00', [$this->b->id])->assertSessionHasNoErrors();
    $this->actingAs($this->ana)->patch(route('a-receber.recebido', QuitacaoContato::query()->sole()), ['recebido' => true]);
    $this->actingAs($this->ana)->delete(route('faturas.pagamentos.destroy', PagamentoFatura::query()->sole()));

    expect(Lancamento::query()->whereNotNull('alterado_por_id')->exists())->toBeFalse();
});

it('CA-208.14 shows the row purchases to the member without any form', function () {
    $itens = linhaDaFatura($this->ana, $this->x)['itens'];

    expect($itens)->toHaveCount(2)
        ->and(collect($itens)->every(fn (array $item) => $item['edicao'] === null && $item['participacao'] === null))->toBeTrue()
        ->and(collect(linhaDaFatura($this->user, $this->x)['itens'])->every(fn (array $item) => $item['edicao'] !== null))->toBeTrue();
});

it('CA-208.6 refuses the member payment when the invoice is no longer linked to a delegated wallet', function () {
    $fatura = faturaDe($this->nubank, '2026-12');
    $fatura->update(['carteira_vinculada_id' => $this->outra->id]);

    expect(fn () => app(PagarFatura::class)->handle($this->nubank, $fatura->referencia, 1000, '2026-11-14', pagoPor: $this->ana))
        ->toThrow(AuthorizationException::class)
        ->and(PagamentoFatura::query()->count())->toBe(0);
});
