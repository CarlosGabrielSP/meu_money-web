<?php

use App\Actions\Cartoes\Espelhos;
use App\Actions\Cartoes\PagarFatura;
use App\Enums\TipoCategoria;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Base scenario of Módulo 17 (nota 06): today 2026-11-14; A with X (default, income of 5000,00) and
 * the Nubank (due day 10, closing 10 days before, paid by X); contact B with Z (default, income of
 * 3000,00); "Restaurante" 200,00 on 2026-11-05 split equally with B and "Mercado" 100,00 of A only on
 * 2026-11-20. Invoices are named by the due month, as in Módulo 16.
 */
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->user = User::factory()->create(['name' => 'A']);
    $this->b = User::factory()->create(['name' => 'B']);
    Contato::factory()->aceito()->create(['solicitante_id' => $this->user->id, 'convidado_id' => $this->b->id]);
    $this->x = Carteira::factory()->for($this->user)->padrao()->create(['nome' => 'X']);
    $this->z = Carteira::factory()->for($this->b)->padrao()->create(['nome' => 'Z']);
    $this->lazer = Categoria::query()->whereNull('user_id')->where('tipo', '!=', TipoCategoria::Receita)->firstOrFail();
    $entrada = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Ambos]);
    lancamentoComCota($this->user, $this->x, $entrada, ['data' => '2026-11-05', 'tipo' => 'receita', 'valor' => 500000], ['executado' => true]);
    lancamentoComCota($this->b, $this->z, $entrada, ['data' => '2026-11-05', 'tipo' => 'receita', 'valor' => 300000], ['executado' => true]);
    $this->nubank = cartaoDe($this->user, 'Nubank', $this->x);

    $this->restaurante = compraRateada($this->nubank, 'Restaurante', '200,00', '2026-11-05');
    salvarLancamento(['descricao' => 'Mercado', 'valor' => '100,00', 'data' => '2026-11-20', 'carteira_id' => $this->nubank->id]);
});

/**
 * Form data of an entry of A (RF-070), shared with B when $comB.
 *
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosDeA(array $dados = [], bool $comB = false): array
{
    $teste = test();

    return [
        'tipo' => 'despesa',
        'descricao' => 'Compra',
        'valor' => '100,00',
        'data' => '2026-11-20',
        'categoria_id' => $teste->lazer->id,
        'carteira_id' => $teste->nubank->id,
        ...($comB ? ['rateio' => ['modo' => 'iguais', 'participantes' => [['user_id' => $teste->user->id], ['user_id' => $teste->b->id]]]] : []),
        ...$dados,
    ];
}

/**
 * @param  array<string, mixed>  $dados
 */
function salvarLancamento(array $dados, bool $comB = false, ?Lancamento $lancamento = null): TestResponse
{
    $teste = test();
    $rota = $lancamento === null ? route('lancamentos.store') : route('lancamentos.update', $lancamento);
    $metodo = $lancamento === null ? 'post' : 'put';

    return $teste->actingAs($teste->user)->{$metodo}($rota, dadosDeA($dados, $comB));
}

function compraRateada(Carteira $carteira, string $descricao, string $valor, string $data): Lancamento
{
    salvarLancamento(['descricao' => $descricao, 'valor' => $valor, 'data' => $data, 'carteira_id' => $carteira->id], comB: true)->assertSessionHasNoErrors();

    return test()->user->lancamentos()->where('descricao', $descricao)->latest('id')->firstOrFail();
}

/**
 * @param  array<string, mixed>  $parametros
 */
function telaDe(Carteira $carteira, array $parametros = []): TestResponse
{
    return test()->actingAs(test()->user)->get(route('home', ['carteira' => $carteira->id, ...$parametros]));
}

/**
 * B's mirror of the author's card, or null.
 */
function espelhoDe(Carteira $cartao): ?Carteira
{
    return Carteira::query()->whereHas('cartao', fn ($query) => $query->where('cartao_origem_id', $cartao->id))->first();
}

function cotaDeB(Lancamento $lancamento): Cota
{
    return $lancamento->cotas()->where('user_id', test()->b->id)->with('fatura')->sole();
}

it('CA-180.1 creates the mirror on the first share, with the card days and linked to the default wallet', function () {
    $espelho = espelhoDe($this->nubank);

    expect($espelho)->user_id->toBe($this->b->id)->nome->toBe('Nubank · A')->tipo->value->toBe('cartao')
        ->icone->value->toBe('credit-card')->cor->value->toBe('blue')
        ->and($espelho->cartao)->carteira_vinculada_id->toBe($this->z->id)->dia_vencimento->toBe(10)->dias_fechamento->toBe(10)
        ->and(cotaDeB($this->restaurante))->carteira_id->toBe($espelho->id)->valor->toBe(10000)
        ->and(cotaDeB($this->restaurante)->fatura->referencia->toDateString())->toBe('2026-12-01');
});

it('CA-180.2 sends another shared purchase to the same mirror', function () {
    $outra = compraRateada($this->nubank, 'Bar', '60,00', '2026-11-21');

    expect(cotaDeB($outra)->carteira_id)->toBe(espelhoDe($this->nubank)->id)
        ->and(Cartao::query()->whereNotNull('cartao_origem_id')->count())->toBe(1);
});

it('CA-180.4 reduces both parts with shared income in the card', function () {
    salvarLancamento(['tipo' => 'receita', 'descricao' => 'Estorno', 'valor' => '40,00', 'data' => '2026-11-08', 'categoria_id' => Categoria::outros()->id], comB: true)->assertSessionHasNoErrors();

    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.partes.0.parte', 18000)
        ->where('fatura.partes.1.nome', 'B')
        ->where('fatura.partes.1.parte', 8000)
        ->where('fatura.aPagar', 26000));
});

it('CA-180.5 keeps the existing mirror when the contact changes the default wallet', function () {
    $w = Carteira::factory()->for($this->b)->create(['nome' => 'W']);
    $this->actingAs($this->b)->post(route('carteiras.padrao', $w))->assertSessionHasNoErrors();
    $itau = cartaoDe($this->user, 'Itaú', $this->x);

    compraRateada($itau, 'Livro', '50,00', '2026-11-21');

    expect(espelhoDe($this->nubank)->cartao->carteira_vinculada_id)->toBe($this->z->id)
        ->and(espelhoDe($itau)->cartao->carteira_vinculada_id)->toBe($w->id);
});

it('CA-180.6 lists the mirror share among the contact received shares', function () {
    expect(Cota::query()->recebidasPor($this->b)->pluck('id')->all())->toBe([cotaDeB($this->restaurante)->id])
        ->and(cotaDeB($this->restaurante)->vista_em)->toBeNull();
});

it('CA-180.7 moves the contact share to the mirror linked wallet when the purchase leaves the card', function () {
    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-05', 'carteira_id' => $this->x->id], comB: true, lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();

    expect(cotaDeB($this->restaurante))->carteira_id->toBe($this->z->id)->executado->toBeTrue()->fatura_id->toBeNull()
        ->and($this->restaurante->cotas()->where('user_id', $this->user->id)->sole())->carteira_id->toBe($this->x->id)->executado->toBeTrue()
        ->and(espelhoDe($this->nubank))->toBeNull();
});

it('CA-180.8 sends a pending contact share to the mirror when the purchase enters the card', function () {
    $jantar = compraRateada($this->x, 'Jantar', '80,00', '2026-11-10');
    cotaDeB($jantar)->forceFill(['executado' => false, 'executado_em' => null])->save();

    salvarLancamento(['descricao' => 'Jantar', 'valor' => '80,00', 'data' => '2026-11-10'], comB: true, lancamento: $jantar)->assertSessionHasNoErrors();

    expect(cotaDeB($jantar))->carteira_id->toBe(espelhoDe($this->nubank)->id)->executado->toBeFalse()
        ->and(cotaDeB($jantar)->fatura->referencia->toDateString())->toBe('2026-12-01');
});

it('CA-180.8 refuses moving a purchase to the card when the contact executed the share', function () {
    $jantar = compraRateada($this->x, 'Jantar', '80,00', '2026-11-10');
    cotaDeB($jantar)->forceFill(['executado' => true, 'executado_em' => now()])->save();

    salvarLancamento(['descricao' => 'Jantar', 'valor' => '80,00', 'data' => '2026-11-10'], comB: true, lancamento: $jantar)
        ->assertSessionHasErrors(['rateio' => 'B já marcou a cota como executada. Valor e divisão não podem mudar.']);

    expect(cotaDeB($jantar)->carteira_id)->toBe($this->z->id)
        ->and($jantar->cotas()->where('user_id', $this->user->id)->sole()->carteira_id)->toBe($this->x->id);
});

it('CA-180.8 moves no share when another entry has an executed contact share', function () {
    $jantar = compraRateada($this->x, 'Jantar', '80,00', '2026-11-10');
    $almoco = compraRateada($this->x, 'Almoço', '60,00', '2026-11-10');
    cotaDeB($jantar)->forceFill(['executado' => false, 'executado_em' => null])->save();
    cotaDeB($almoco)->forceFill(['executado' => true, 'executado_em' => now()])->save();
    Cota::query()->whereIn('lancamento_id', [$jantar->id, $almoco->id])->where('user_id', $this->user->id)->update(['carteira_id' => $this->nubank->id]);

    expect(fn () => app(Espelhos::class)->acompanharLancamentos([$jantar->id, $almoco->id]))->toThrow(ValidationException::class);

    expect(cotaDeB($jantar)->carteira_id)->toBe($this->z->id);
});

it('RN-261 c forgets a deleted mirror so the next share creates a new one', function () {
    $espelhos = app(Espelhos::class);
    $antigo = $espelhos->espelho($this->nubank, $this->b->id);
    Cota::query()->where('carteira_id', $antigo)->delete();
    $espelhos->excluirVazios([$antigo]);

    $novo = $espelhos->espelho($this->nubank, $this->b->id);

    expect($novo)->not->toBe($antigo)
        ->and(Carteira::query()->find($novo))->not->toBeNull();
});

it('CA-180.9 moves the contact share to the mirror of the new card', function () {
    $itau = cartaoDe($this->user, 'Itaú', $this->x);

    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-05', 'carteira_id' => $itau->id], comB: true, lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();

    expect(espelhoDe($itau))->nome->toBe('Itaú · A')->cartao->carteira_vinculada_id->toBe($this->z->id)
        ->and(cotaDeB($this->restaurante)->carteira_id)->toBe(espelhoDe($itau)->id)
        ->and(espelhoDe($this->nubank))->toBeNull();
});

it('CA-180.11 sends each installment share of a series to the mirror invoice of its date', function () {
    $this->actingAs($this->user)->post(route('lancamentos.store'), [
        ...dadosDeA(['descricao' => 'Curso', 'valor' => '90,00', 'data' => '2026-11-05'], comB: true),
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 3,
        'modo_valor' => 'parcela',
    ])->assertSessionHasNoErrors();

    $cotas = Cota::query()->where('user_id', $this->b->id)
        ->whereHas('lancamento', fn ($query) => $query->where('descricao', 'Curso'))
        ->with('fatura')->get();

    expect($cotas->pluck('carteira_id')->unique()->all())->toBe([espelhoDe($this->nubank)->id])
        ->and($cotas->pluck('valor')->all())->toBe([4500, 4500, 4500])
        ->and($cotas->map(fn (Cota $cota) => $cota->fatura->referencia->toDateString())->sort()->values()->all())
        ->toBe(['2026-12-01', '2027-01-01', '2027-02-01']);
});

it('CA-181.1 copies the dates of the card invoice', function () {
    expect(faturaDe(espelhoDe($this->nubank), '2026-12'))
        ->fechamento->toDateString()->toBe('2026-11-30')
        ->vencimento->toDateString()->toBe('2026-12-10');
});

it('CA-181.2 follows a closing edited on the card and moves the shares with it', function () {
    $tarde = compraRateada($this->nubank, 'Padaria', '20,00', '2026-11-29');

    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-28', 'vencimento' => '2026-12-10'])
        ->assertSessionHasNoErrors();

    expect(faturaDe(espelhoDe($this->nubank), '2026-12')->fechamento->toDateString())->toBe('2026-11-28')
        ->and(cotaDeB($tarde)->fatura->referencia->toDateString())->toBe('2027-01-01')
        ->and($tarde->cotas()->where('user_id', $this->user->id)->sole()->fatura->referencia->toDateString())->toBe('2027-01-01');
});

it('CA-181.3 follows a new due day of the card', function () {
    $this->actingAs($this->user)->put(route('carteiras.update', $this->nubank), [
        'nome' => 'Nubank', 'dia_vencimento' => 15, 'dias_fechamento' => 10, 'competencia' => 'vencimento', 'carteira_vinculada_id' => $this->x->id,
    ])->assertSessionHasNoErrors();

    $espelho = espelhoDe($this->nubank);

    expect($espelho->cartao->dia_vencimento)->toBe(15)
        ->and(faturaDe($espelho, '2026-12'))->vencimento->toDateString()->toBe('2026-12-15')->fechamento->toDateString()->toBe('2026-12-05');
});

it('CA-181.4 refuses date changes by the contact', function () {
    $espelho = espelhoDe($this->nubank);

    $this->actingAs($this->b)
        ->put(route('faturas.datas', [$espelho, '2026-12']), ['fechamento' => '2026-11-28', 'vencimento' => '2026-12-10'])
        ->assertSessionHasErrors(['fechamento' => 'Definidos pelo cartão de A.']);
    $this->actingAs($this->b)
        ->put(route('carteiras.update', $espelho), ['nome' => 'Nubank · A', 'dia_vencimento' => 15, 'carteira_vinculada_id' => $this->z->id])
        ->assertSessionHasErrors(['dia_vencimento' => 'Definidos pelo cartão de A.']);

    expect($espelho->cartao->fresh()->dia_vencimento)->toBe(10)
        ->and(faturaDe($espelho, '2026-12')->fechamento->toDateString())->toBe('2026-11-30');
});

it('CA-181.6 takes the edited dates of a card invoice stored before the first share', function () {
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2027-01']), ['fechamento' => '2026-12-28', 'vencimento' => '2027-01-10'])
        ->assertSessionHasNoErrors();

    $ceia = compraRateada($this->nubank, 'Ceia', '40,00', '2026-12-20');

    expect(cotaDeB($ceia)->fatura)
        ->referencia->toDateString()->toBe('2027-01-01')
        ->fechamento->toDateString()->toBe('2026-12-28')
        ->vencimento->toDateString()->toBe('2027-01-10');
});

it('CA-182.1 lets the contact rename the mirror and change the wallet that pays it', function () {
    $w = Carteira::factory()->for($this->b)->create(['nome' => 'W']);
    $espelho = espelhoDe($this->nubank);

    $this->actingAs($this->b)
        ->put(route('carteiras.update', $espelho), ['nome' => 'Cartão do A', 'cor' => 'red', 'icone' => 'wallet', 'carteira_vinculada_id' => $w->id])
        ->assertSessionHasNoErrors();

    expect($espelho->fresh())->nome->toBe('Cartão do A')->cor->value->toBe('red')->icone->value->toBe('wallet')
        ->and($espelho->cartao->fresh()->carteira_vinculada_id)->toBe($w->id)
        ->and(faturaDe($espelho, '2026-12')->carteira_vinculada_id)->toBe($w->id);
});

it('CA-182.2 requires a common wallet of the contact to pay the mirror', function (?string $vinculada) {
    $espelho = espelhoDe($this->nubank);
    $cartaoDeB = cartaoDe($this->b, 'Inter');

    $this->actingAs($this->b)
        ->put(route('carteiras.update', $espelho), ['nome' => 'Nubank · A', 'carteira_vinculada_id' => $vinculada === 'cartao' ? $cartaoDeB->id : null])
        ->assertSessionHasErrors('carteira_vinculada_id');

    expect($espelho->cartao->fresh()->carteira_vinculada_id)->toBe($this->z->id);
})->with(['none' => [null], 'a card' => ['cartao']]);

it('CA-182.3 refuses deleting a mirror with parts', function () {
    $espelho = espelhoDe($this->nubank);

    $this->actingAs($this->b)
        ->delete(route('carteiras.destroy', $espelho), ['modo' => 'excluir'])
        ->assertSessionHasErrors(['modo' => 'Este cartão tem compras divididas com A.']);

    expect($espelho->fresh())->not->toBeNull();
});

it('CA-182.4 refuses making the mirror the default wallet', function () {
    $this->actingAs($this->b)->post(route('carteiras.padrao', espelhoDe($this->nubank)))->assertSessionHasErrors('carteira');

    expect($this->z->fresh()->padrao)->toBeTrue();
});

it('CA-182.5 deletes the mirror left without parts and payments', function () {
    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-05'], lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();

    expect($this->restaurante->cotas()->count())->toBe(1)
        ->and(espelhoDe($this->nubank))->toBeNull();
});

it('CA-182.5 deletes the mirror when the last shared purchase is deleted', function () {
    $this->actingAs($this->user)->delete(route('lancamentos.destroy', $this->restaurante))->assertSessionHasNoErrors();

    expect(espelhoDe($this->nubank))->toBeNull();
});

it('CA-182.6 keeps a mirror without parts that has a payment', function () {
    $espelho = espelhoDe($this->nubank);
    app(PagarFatura::class)->handle($espelho, CarbonImmutable::parse('2026-12-01'), 5000, '2026-11-14');

    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-05'], lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();

    expect($espelho->fresh())->not->toBeNull();
});

it('RN-261 c deletes the mirror when its last payment is deleted and it has no parts', function () {
    $espelho = espelhoDe($this->nubank);
    app(PagarFatura::class)->handle($espelho, CarbonImmutable::parse('2026-12-01'), 5000, '2026-11-14');
    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-05'], lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();

    $pagamento = $espelho->faturas()->firstOrFail()->pagamentos()->firstOrFail();
    $this->actingAs($this->b)->delete(route('faturas.pagamentos.destroy', $pagamento))->assertSessionHasNoErrors();

    expect(espelhoDe($this->nubank))->toBeNull();
});

it('RN-261 c keeps the mirror with parts when its payment is deleted', function () {
    $espelho = espelhoDe($this->nubank);
    app(PagarFatura::class)->handle($espelho, CarbonImmutable::parse('2026-12-01'), 5000, '2026-11-14');

    $pagamento = $espelho->faturas()->firstOrFail()->pagamentos()->firstOrFail();
    $this->actingAs($this->b)->delete(route('faturas.pagamentos.destroy', $pagamento))->assertSessionHasNoErrors();

    expect($espelho->fresh())->not->toBeNull();
});

it('CA-182.8 refuses entries of the contact in the mirror and moving a share into it', function () {
    $espelho = espelhoDe($this->nubank);
    $deB = Categoria::factory()->for($this->b)->create();

    $this->actingAs($this->b)->post(route('lancamentos.store'), [
        'tipo' => 'despesa', 'descricao' => 'Própria', 'valor' => '10,00', 'data' => '2026-11-20',
        'categoria_id' => $deB->id, 'carteira_id' => $espelho->id,
    ])->assertSessionHasErrors(['carteira_id' => 'Este cartão recebe só as compras divididas com A.']);

    $jantar = compraRateada($this->x, 'Jantar', '80,00', '2026-11-20');
    $this->actingAs($this->b)
        ->put(route('cotas.update', cotaDeB($jantar)), ['carteira_id' => $espelho->id, 'categoria_id' => $this->lazer->id, 'executado' => false])
        ->assertSessionHasErrors(['carteira_id' => 'Este cartão recebe só as compras divididas com A.']);

    expect(cotaDeB($jantar)->carteira_id)->toBe($this->z->id)
        ->and($espelho->cotas()->count())->toBe(1);

    $this->actingAs($this->b)->get(route('home', ['carteira' => $this->z->id]))
        ->assertInertia(fn (Assert $page) => $page->where('carteiras', fn ($carteiras) => collect($carteiras)->firstWhere('id', $espelho->id)['espelho'] === true));
});

it('CA-183.1 shows the invoice with the parts of the author and the contact', function () {
    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.compras', 20000)
        ->where('fatura.comprasContatos', 10000)
        ->where('fatura.aPagar', 30000)
        ->where('fatura.partes', [
            ['userId' => $this->user->id, 'nome' => 'Você', 'voce' => true, 'parte' => 20000, 'quitadoPeloContato' => 0, 'quitadoPeloAutor' => 0, 'aberto' => 20000],
            ['userId' => $this->b->id, 'nome' => 'B', 'voce' => false, 'parte' => 10000, 'quitadoPeloContato' => 0, 'quitadoPeloAutor' => 0, 'aberto' => 10000],
        ]));
});

it('CA-183.3 carries the contact open part to the next invoice', function () {
    app(PagarFatura::class)->handle($this->nubank, CarbonImmutable::parse('2026-12-01'), 20000, '2026-11-14');

    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page->where('fatura.aPagar', 10000));
    telaDe($this->nubank, ['mes' => '2027-01'])->assertInertia(fn (Assert $page) => $page->where('fatura.saldoAnterior', 10000));
});

it('RN-257 shows nothing settled and nothing open when the author part is zero or less', function () {
    salvarLancamento(['tipo' => 'receita', 'descricao' => 'Estorno', 'valor' => '300,00', 'data' => '2026-11-15', 'carteira_id' => $this->nubank->id, 'categoria_id' => Categoria::outros()->id])
        ->assertSessionHasNoErrors();

    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.partes.0.userId', $this->user->id)
        ->where('fatura.partes.0.parte', -10000)
        ->where('fatura.partes.0.quitadoPeloAutor', 0)
        ->where('fatura.partes.0.aberto', 0)
        ->where('fatura.partes.1.userId', $this->b->id));
});

it('CA-183.4 lists a shared purchase with its total and the author part', function () {
    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('lancamentos.despesa.avista.0.descricao', 'Restaurante')
        ->where('lancamentos.despesa.avista.0.total', 20000)
        ->where('lancamentos.despesa.avista.0.valor', 10000)
        ->where('lancamentos.despesa.avista.0.compartilhado', true)
        ->where('lancamentos.despesa.avista.1.descricao', 'Mercado')
        ->where('lancamentos.despesa.avista.1.compartilhado', false)
        ->where('fatura.partes', fn ($partes) => count($partes) === 2));
});

it('CA-183.5 opens the card screen in the same number of queries with one or five contacts', function () {
    $contar = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        telaDe($this->nubank, ['mes' => '2026-12'])->assertOk();

        return count(DB::getQueryLog());
    };
    $contar();
    $comUm = $contar();

    foreach (range(1, 4) as $indice) {
        $outro = User::factory()->create();
        Carteira::factory()->for($outro)->padrao()->create();
        Contato::factory()->aceito()->create(['solicitante_id' => $this->user->id, 'convidado_id' => $outro->id]);
        salvarLancamento(['descricao' => "Bar {$indice}", 'rateio' => ['modo' => 'iguais', 'participantes' => [['user_id' => $this->user->id], ['user_id' => $outro->id]]]])
            ->assertSessionHasNoErrors();
    }

    expect($contar())->toBe($comUm);
});

it('CA-183.6 counts the author payments as the author settled part', function () {
    app(PagarFatura::class)->handle($this->nubank, CarbonImmutable::parse('2026-12-01'), 15000, '2026-11-14');

    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.partes.0.quitadoPeloAutor', 15000)
        ->where('fatura.partes.0.aberto', 5000));
});

it('CA-182.9 refuses the mirror as destination when the contact deletes a wallet', function () {
    $espelho = espelhoDe($this->nubank);
    $c = cartaoDe($this->b, 'C', $this->z);
    $deB = Categoria::factory()->for($this->b)->create();
    lancamentoComCota($this->b, $c, $deB, ['data' => '2026-11-20', 'valor' => 3000]);

    $this->actingAs($this->b)->delete(route('carteiras.destroy', $c), ['modo' => 'mover', 'destino_id' => $espelho->id])
        ->assertSessionHasErrors(['destino_id' => 'Este cartão recebe só as compras divididas com A.']);

    expect($c->fresh())->not->toBeNull()
        ->and($espelho->cotas()->count())->toBe(1);

    $this->actingAs($this->b)->get(route('carteiras.index'))->assertInertia(fn (Assert $page) => $page
        ->where('carteiras', fn ($carteiras) => collect($carteiras)->firstWhere('id', $espelho->id)['temCotas'] === true
            && collect($carteiras)->firstWhere('id', $c->id)['temCotas'] === true));
});

it('CA-187.1 weighs only the author part on the linked wallet', function () {
    telaDe($this->x, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.despesas', 20000)
        ->where('saldos.aPagar', 20000)
        ->where('saldos.acumulado.estimado', 480000));
});

it('CA-187.4 counts the contact parts in the card debt', function () {
    $this->actingAs($this->user)->get(route('relatorio', ['carteira' => $this->x->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cartoes.0.atual', -20000)
            ->where('cartoes.0.estimado', -30000));
});

it('CA-187.5 forecasts only the author part on the linked wallet', function () {
    $this->actingAs($this->user)->get(route('relatorio', ['carteira' => $this->x->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('previsao.meses.0.mes', '2026-12')
            ->where('previsao.meses.0.resultado', -20000));
});
