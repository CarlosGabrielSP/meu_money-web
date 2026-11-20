<?php

use App\Actions\Cartoes\AtribuirFaturas;
use App\Enums\OrigemQuitacao;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Lancamento;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\RecebimentoContato;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Stage 15.2 of Módulo 17 (nota 06): payments, settlements and "a receber", on the base scenario.
 */
beforeEach(fn () => cenarioEspelho());

/**
 * Pay an invoice of the card as its owner, through the "Pagar" form (RF-165, RF-185).
 *
 * @param  list<int>  $contatos
 */
function pagarComo(User $user, Carteira $cartao, string $mes, string $valor, array $contatos = [], string $data = '2026-11-14'): TestResponse
{
    return test()->actingAs($user)->post(route('faturas.pagar', [$cartao, $mes]), ['valor' => $valor, 'data' => $data, 'contatos' => $contatos]);
}

function pagarNoEspelho(string $valor, string $mes = '2026-12', string $data = '2026-11-14'): PagamentoFatura
{
    $espelho = espelhoDe(test()->nubank);
    pagarComo(test()->b, $espelho, $mes, $valor, data: $data)->assertSessionHasNoErrors();

    return PagamentoFatura::query()->whereHas('fatura', fn ($query) => $query->where('carteira_id', $espelho->id))->latest('id')->firstOrFail();
}

function pagarComB(string $valor = '200,00', string $mes = '2026-12'): PagamentoFatura
{
    pagarComo(test()->user, test()->nubank, $mes, $valor, [test()->b->id])->assertSessionHasNoErrors();

    return PagamentoFatura::query()->whereHas('fatura', fn ($query) => $query->where('carteira_id', test()->nubank->id))->latest('id')->firstOrFail();
}

/**
 * Settlements of B on the Nubank as [reference month, origin, amount], oldest first.
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function quitacoesDeB(): array
{
    return QuitacaoContato::query()
        ->where('contato_id', test()->b->id)
        ->with('fatura:id,referencia')
        ->orderBy('id')
        ->get()
        ->map(fn (QuitacaoContato $quitacao): array => [$quitacao->fatura->referencia->format('Y-m'), $quitacao->origem->value, $quitacao->valor])
        ->all();
}

function aReceberDeB(): QuitacaoContato
{
    return QuitacaoContato::query()->where('origem', OrigemQuitacao::Autor->value)->where('contato_id', test()->b->id)->sole();
}

/**
 * The October purchase of CA-184.3: B's part of 50,00 in the November invoice.
 */
function compraDeOutubro(): Lancamento
{
    $compra = compraRateada(test()->nubank, 'Outubro', '100,00', '2026-11-20');
    $compra->forceFill(['data' => '2026-10-15'])->save();
    app(AtribuirFaturas::class)->handle($compra->cotas()->pluck('id'), permitirFechadas: true);

    return $compra;
}

function aPagarDoNubank(string $mes): int
{
    $aPagar = 0;
    telaDe(test()->nubank, ['mes' => $mes])->assertInertia(function (Assert $page) use (&$aPagar) {
        $aPagar = $page->toArray()['props']['fatura']['aPagar'];
    });

    return $aPagar;
}

it('CA-184.1 settles the contact part on the author invoice with the mirror payment', function () {
    $pagamento = pagarNoEspelho('100,00');

    expect($pagamento->carteira_id)->toBe($this->z->id)
        ->and(quitacoesDeB())->toBe([['2026-12', 'contato', 10000]])
        ->and(QuitacaoContato::query()->sole()->pagamento_fatura_id)->toBe($pagamento->id);
    $this->actingAs($this->b)->get(route('home', ['carteira' => $this->z->id]))
        ->assertInertia(fn (Assert $page) => $page->where('saldos.acumulado.atual', 290000));
});

it('CA-184.2 keeps the excess as mirror credit and settles only the open part', function () {
    pagarNoEspelho('150,00');

    expect(quitacoesDeB())->toBe([['2026-12', 'contato', 10000]])
        ->and(aPagarDoNubank('2026-12'))->toBe(20000);
    $this->actingAs($this->b)->get(route('home', ['carteira' => espelhoDe($this->nubank)->id, 'mes' => '2026-12']))
        ->assertInertia(fn (Assert $page) => $page->where('fatura.aPagar', -5000));
});

it('CA-184.3 settles the oldest open parts first, up to the payment reference month', function () {
    compraDeOutubro();

    pagarNoEspelho('150,00');

    expect(quitacoesDeB())->toBe([['2026-11', 'contato', 5000], ['2026-12', 'contato', 10000]]);
});

it('CA-184.4 undoes the settlement when the contact deletes the payment', function () {
    $pagamento = pagarNoEspelho('100,00');

    $this->actingAs($this->b)->delete(route('faturas.pagamentos.destroy', $pagamento))->assertSessionHasNoErrors();

    expect(QuitacaoContato::query()->count())->toBe(0)
        ->and(aPagarDoNubank('2026-12'))->toBe(30000);
});

it('CA-184.5 sends an advance payment to the oldest open part', function () {
    pagarNoEspelho('30,00', mes: '2027-01');

    expect(quitacoesDeB())->toBe([['2026-12', 'contato', 3000]]);
});

it('CA-184.6 settles the open part and then receives the author settlement of each invoice', function () {
    compraDeOutubro();
    pagarComB();

    pagarNoEspelho('120,00');

    expect(quitacoesDeB())->toBe([['2026-12', 'autor', 10000], ['2026-11', 'contato', 5000]])
        ->and(RecebimentoContato::query()->sole())->valor->toBe(7000)->pagamento_fatura_id->not->toBeNull();
});

it('CA-184.7 leaves a new part open until the next payment rebuilds the distribution', function () {
    pagarNoEspelho('150,00');
    compraRateada($this->nubank, 'Bar', '100,00', '2026-11-21');

    expect(quitacoesDeB())->toBe([['2026-12', 'contato', 10000]]);

    pagarNoEspelho('10,00');

    expect(collect(quitacoesDeB())->sum(fn (array $quitacao): int => $quitacao[2]))->toBe(15000)
        ->and(collect(quitacoesDeB())->pluck(0)->unique()->all())->toBe(['2026-12']);
});

it('CA-185.1 settles the contact part with the author payment and creates the "a receber"', function () {
    $pagamento = pagarComB();

    expect($pagamento)->valor->toBe(30000)->carteira_id->toBe($this->x->id)
        ->and(quitacoesDeB())->toBe([['2026-12', 'autor', 10000]]);
    telaDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->has('aReceber', 1)
        ->where('aReceber.0.nome', 'B')
        ->where('aReceber.0.valor', 10000)
        ->where('aReceber.0.recebido', 0)
        ->where('aReceber.0.data', '2026-11-14'));
});

it('CA-185.2 settles only what the contact left open', function () {
    pagarNoEspelho('30,00');
    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page->where('fatura.partes.1.aberto', 7000));

    $pagamento = pagarComB();

    expect($pagamento->valor)->toBe(27000)
        ->and(aReceberDeB()->valor)->toBe(7000);
});

it('CA-185.3 skips a contact whose part is all settled', function () {
    pagarNoEspelho('100,00');

    $pagamento = pagarComB('50,00');

    expect($pagamento->valor)->toBe(5000)
        ->and(QuitacaoContato::query()->where('origem', 'autor')->count())->toBe(0);
});

it('CA-185.4 redistributes the contact payment when the author deletes the settling payment', function () {
    $doAutor = pagarComB();
    pagarNoEspelho('100,00');
    expect(RecebimentoContato::query()->sole()->valor)->toBe(10000);

    $this->actingAs($this->user)->delete(route('faturas.pagamentos.destroy', $doAutor))->assertSessionHasNoErrors();

    expect(quitacoesDeB())->toBe([['2026-12', 'contato', 10000]])
        ->and(RecebimentoContato::query()->count())->toBe(0);
});

it('CA-185.5 creates no "a receber" on an independent card', function () {
    Cartao::query()->where('carteira_id', $this->nubank->id)->update(['carteira_vinculada_id' => null]);
    $this->nubank->faturas()->update(['carteira_vinculada_id' => null]);

    pagarComB();

    expect(quitacoesDeB())->toBe([['2026-12', 'autor', 10000]]);
    telaDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page->where('aReceber', []));
});

it('CA-185.6 keeps the contact mirror to pay after the author settlement', function () {
    pagarComB();

    $this->actingAs($this->b)->get(route('home', ['carteira' => espelhoDe($this->nubank)->id, 'mes' => '2026-12']))
        ->assertInertia(fn (Assert $page) => $page->where('fatura.aPagar', 10000));
});

it('CA-185.7 settles only the part of the invoice being paid', function () {
    compraDeOutubro();

    pagarComB();

    expect(quitacoesDeB())->toBe([['2026-12', 'autor', 10000]]);
    telaDe($this->nubank, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page->where('fatura.partes.1.aberto', 5000));
});

it('CA-185.8 accepts an empty typed amount when a contact is marked, and refuses it otherwise', function () {
    pagarComo($this->user, $this->nubank, '2026-12', '200,00')->assertSessionHasNoErrors();

    $pagamento = pagarComB('');

    expect($pagamento->valor)->toBe(10000)
        ->and(quitacoesDeB())->toBe([['2026-12', 'autor', 10000]]);
    pagarComo($this->user, $this->nubank, '2026-12', '')->assertSessionHasErrors(['valor' => 'Informe um valor maior que zero.']);
});

it('refuses a payment of zero when the marked contact has nothing open (RN-264)', function () {
    pagarNoEspelho('100,00');

    pagarComo($this->user, $this->nubank, '2026-12', '', [$this->b->id])->assertSessionHasErrors(['valor' => 'Informe um valor maior que zero.']);
});

it('S3 suggests the author own to pay, without the contacts open parts', function () {
    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.aPagar', 30000)
        ->where('fatura.aPagarAutor', 20000));
});

it('CA-186.1 receives the "a receber" automatically with the contact mirror payment', function () {
    pagarComB();

    $doContato = pagarNoEspelho('100,00');

    expect(RecebimentoContato::query()->sole())->valor->toBe(10000)->pagamento_fatura_id->toBe($doContato->id)
        ->and(RecebimentoContato::query()->sole()->data->toDateString())->toBe('2026-11-14');
    telaDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('aReceber.0.recebido', 10000)
        ->where('saldos.acumulado.atual', 480000));
});

it('CA-186.2 shows a partial receipt', function () {
    pagarComB();
    pagarNoEspelho('40,00');

    telaDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('aReceber.0.recebido', 4000)
        ->where('aReceber.0.valor', 10000)
        ->where('aReceber.0.manual', false));
});

it('CA-186.3 receives the rest manually and unmarking undoes only the manual receipt', function () {
    pagarComB();
    pagarNoEspelho('40,00');
    $item = aReceberDeB();

    $this->actingAs($this->user)->patch(route('a-receber.recebido', $item), ['recebido' => true])->assertSessionHasNoErrors();

    expect($item->recebimentos()->whereNull('pagamento_fatura_id')->sole())->valor->toBe(6000)
        ->and($item->recebimentos()->whereNull('pagamento_fatura_id')->sole()->data->toDateString())->toBe('2026-11-14');

    $this->actingAs($this->user)->patch(route('a-receber.recebido', $item), ['recebido' => false])->assertSessionHasNoErrors();

    expect($item->recebimentos()->pluck('valor')->all())->toBe([4000]);
});

it('CA-186.4 changes nothing for the author when the contact pays more after the item is received', function () {
    pagarComB();
    pagarNoEspelho('100,00');

    pagarNoEspelho('50,00');

    expect(RecebimentoContato::query()->sum('valor'))->toBe(10000)
        ->and(quitacoesDeB())->toBe([['2026-12', 'autor', 10000]]);
});

it('CA-186.5 keeps the "a receber" out of the month income and the report', function () {
    pagarComB();

    telaDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->has('aReceber', 1)
        ->where('saldos.receitas', 500000)
        ->where('lancamentos.receita.avista', fn ($itens) => count($itens) === 1));
    $this->actingAs($this->user)->get(route('relatorio', ['carteira' => $this->x->id]))
        ->assertInertia(fn (Assert $page) => $page->where('resumo.receitas', 500000));
});

it('CA-186.6 returns the item to pending when the contact deletes the payment', function () {
    pagarComB();
    $doContato = pagarNoEspelho('100,00');

    $this->actingAs($this->b)->delete(route('faturas.pagamentos.destroy', $doContato))->assertSessionHasNoErrors();

    expect(RecebimentoContato::query()->count())->toBe(0);
});

it('lets only the author change the "a receber" item (RN-242)', function () {
    pagarComB();

    $this->actingAs($this->b)->patch(route('a-receber.recebido', aReceberDeB()), ['recebido' => true])->assertForbidden();

    expect(RecebimentoContato::query()->count())->toBe(0);
});

it('CA-186.8 flags an item received only automatically, so its checkbox stays disabled', function () {
    pagarComB();
    pagarNoEspelho('100,00');

    telaDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('aReceber.0.recebido', 10000)
        ->where('aReceber.0.manual', false));
});

it('CA-186.9 hides the "a receber" with filters other than type income, and keeps it in the simple mode', function () {
    pagarComB();

    telaDe($this->x, ['mes' => '2026-11', 'compartilhado' => 'sim'])->assertInertia(fn (Assert $page) => $page->where('aReceber', []));
    telaDe($this->x, ['mes' => '2026-11', 'tipo' => 'despesa'])->assertInertia(fn (Assert $page) => $page->where('aReceber', []));
    telaDe($this->x, ['mes' => '2026-11', 'tipo' => 'receita'])->assertInertia(fn (Assert $page) => $page->has('aReceber', 1));

    $this->user->forceFill(['modo_simples' => true])->save();
    telaDe($this->x, ['mes' => '2026-11', 'compartilhado' => 'sim'])->assertInertia(fn (Assert $page) => $page->has('aReceber', 1));
});

it('CA-187.2 adds the "a receber" to the estimated balance and keeps the author to pay at zero', function () {
    pagarComB();

    telaDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.acumulado.atual', 470000)
        ->where('saldos.aReceber', 10000)
        ->where('saldos.aPagar', 0)
        ->where('saldos.acumulado.estimado', 480000));
    telaDe($this->x, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.aPagar', 0)
        ->where('saldos.acumulado.estimado', 480000));
});

it('CA-187.3 moves the received amount to the current balance', function () {
    pagarComB();
    pagarNoEspelho('100,00');

    telaDe($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.acumulado.atual', 480000)
        ->where('saldos.aReceber', 0)
        ->where('saldos.acumulado.estimado', 480000));
});

it('CA-187.4 lowers the card debt by the contact settlements', function () {
    pagarNoEspelho('100,00');

    $this->actingAs($this->user)->get(route('relatorio', ['carteira' => $this->x->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cartoes.0.atual', -10000)
            ->where('cartoes.0.estimado', -20000));
});

it('CA-189.1 CA-189.6 locks amount, split and deletion of a purchase with a settled part', function () {
    pagarNoEspelho('100,00');
    $mensagem = 'A parte de B já foi quitada. Exclua o pagamento para mudar valor ou divisão.';

    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '300,00', 'data' => '2026-11-05'], comB: true, lancamento: $this->restaurante)
        ->assertSessionHasErrors(['rateio' => $mensagem]);
    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-05'], lancamento: $this->restaurante)
        ->assertSessionHasErrors(['rateio' => $mensagem]);
    $this->actingAs($this->user)->delete(route('lancamentos.destroy', $this->restaurante))
        ->assertSessionHasErrors(['lancamento' => 'A parte de B já foi quitada. Exclua o pagamento para excluir a compra.']);
    salvarLancamento(['descricao' => 'Jantar', 'valor' => '200,00', 'data' => '2026-11-05', 'observacao' => 'nota'], comB: true, lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();

    expect($this->restaurante->fresh())->descricao->toBe('Jantar')->valor->toBe(20000)
        ->and(cotaDeB($this->restaurante)->valor)->toBe(10000);
});

it('CA-189.2 locks the purchase after a settlement by the author', function () {
    pagarComB();

    $this->actingAs($this->user)->delete(route('lancamentos.destroy', $this->restaurante))->assertSessionHasErrors('lancamento');
});

it('CA-189.3 unlocks the purchase after the settling payment is deleted', function () {
    $pagamento = pagarNoEspelho('100,00');
    $this->actingAs($this->b)->delete(route('faturas.pagamentos.destroy', $pagamento));

    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '300,00', 'data' => '2026-11-05'], comB: true, lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();

    expect(cotaDeB($this->restaurante)->valor)->toBe(15000);
});

it('CA-189.4 locks every purchase of the invoice with the contact part', function () {
    $bar = compraRateada($this->nubank, 'Bar', '60,00', '2026-11-21');
    pagarNoEspelho('100,00');

    $this->actingAs($this->user)->delete(route('lancamentos.destroy', $bar))->assertSessionHasErrors('lancamento');
});

it('CA-189.5 keeps the installment of the settled invoice in a mass edit', function () {
    salvarLancamento([
        'descricao' => 'Curso',
        'valor' => '90,00',
        'data' => '2026-11-05',
        'repetir' => true,
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 3,
        'modo_valor' => 'parcela',
    ], comB: true)->assertSessionHasNoErrors();
    $parcelas = Lancamento::query()->where('descricao', 'Curso')->orderBy('numero_parcela')->get();
    pagarNoEspelho('145,00');

    $this->actingAs($this->user)->put(route('lancamentos.update', $parcelas[1]), dadosDeA([
        'descricao' => 'Curso',
        'valor' => '120,00',
        'data' => $parcelas[1]->data->toDateString(),
        'escopo' => 'todas',
        'dia_vencimento' => 5,
    ], comB: true))->assertSessionHasNoErrors();

    expect(Lancamento::query()->where('descricao', 'Curso')->orderBy('numero_parcela')->pluck('valor')->all())->toBe([9000, 12000, 12000]);
});

it('CA-189.7 accepts a date in the same invoice and refuses one that moves the settled part', function () {
    pagarNoEspelho('100,00');

    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-06'], comB: true, lancamento: $this->restaurante)
        ->assertSessionHasNoErrors();
    salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-12-05'], comB: true, lancamento: $this->restaurante)
        ->assertSessionHasErrors(['data' => 'A parte de B já foi quitada. Exclua o pagamento para mudar valor ou divisão.']);

    expect($this->restaurante->fresh()->data->toDateString())->toBe('2026-11-06');
});

it('CA-180.10 refuses to change the wallet of a purchase with a settled part', function () {
    pagarNoEspelho('100,00');
    $itau = cartaoDe($this->user, 'Itaú', $this->x);
    $mensagem = 'A parte de B já foi quitada. Exclua o pagamento para mudar valor ou divisão.';

    foreach ([$this->x, $itau] as $carteira) {
        salvarLancamento(['descricao' => 'Restaurante', 'valor' => '200,00', 'data' => '2026-11-05', 'carteira_id' => $carteira->id], comB: true, lancamento: $this->restaurante)
            ->assertSessionHasErrors(['rateio' => $mensagem]);
    }

    expect(cotaDeB($this->restaurante)->carteira_id)->toBe(espelhoDe($this->nubank)->id);
});

it('CA-181.5 refuses date changes of the card that would move a settled part', function () {
    compraRateada($this->nubank, 'Bar', '100,00', '2026-11-29');
    pagarNoEspelho('150,00');
    $mensagem = 'A parte de B já foi quitada. Exclua o pagamento para mudar valor ou divisão.';

    $this->actingAs($this->user)->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-28', 'vencimento' => '2026-12-10'])
        ->assertSessionHasErrors(['fechamento' => $mensagem]);
    $this->actingAs($this->user)->put(route('carteiras.update', $this->nubank), [
        'nome' => 'Nubank', 'cor' => 'blue', 'icone' => 'credit-card',
        'dia_vencimento' => 5, 'dias_fechamento' => 10, 'competencia' => 'vencimento', 'carteira_vinculada_id' => $this->x->id,
    ])->assertSessionHasErrors(['dia_vencimento' => $mensagem]);

    expect(faturaDe($this->nubank, '2026-12')->fechamento->toDateString())->toBe('2026-11-30');
});

it('marks the edit data of a purchase with a settled part for the form hint', function () {
    pagarNoEspelho('100,00');

    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('lancamentos.despesa.avista.0.edicao.rateio.bloqueado', true)
        ->where('lancamentos.despesa.avista.0.edicao.rateio.avisoQuitacao', 'A parte de B já foi quitada. Exclua o pagamento para mudar valor ou divisão.'));
});

it('CA-183.6 counts as the author settled part only the payments not used for contacts', function () {
    pagarComB();

    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.partes.0.quitadoPeloAutor', 20000)
        ->where('fatura.partes.0.aberto', 0));
});

it('CA-185.9 sends the contacts open parts to the "Pagar" form in the simple mode', function () {
    $this->user->forceFill(['modo_simples' => true])->save();

    telaDe($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.partes.1.nome', 'B')
        ->where('fatura.partes.1.aberto', 10000)
        ->where('fatura.aPagarAutor', 20000));
});

/**
 * A series of 3 × 90,00 in the Nubank from 2026-11-05, split equally with B (CA-180.11).
 *
 * @return Collection<int, Lancamento>
 */
function cursoRateado(): Collection
{
    salvarLancamento([
        'descricao' => 'Curso',
        'valor' => '90,00',
        'data' => '2026-11-05',
        'repetir' => true,
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 3,
        'modo_valor' => 'parcela',
    ], comB: true)->assertSessionHasNoErrors();

    return Lancamento::query()->where('descricao', 'Curso')->orderBy('numero_parcela')->get();
}

it('RN-254 refuses the mass wallet change of a series with a settled part', function () {
    $parcelas = cursoRateado();
    pagarNoEspelho('145,00');

    $this->actingAs($this->user)->put(route('lancamentos.update', $parcelas[1]), dadosDeA([
        'descricao' => 'Curso',
        'valor' => '90,00',
        'data' => $parcelas[1]->data->toDateString(),
        'carteira_id' => $this->x->id,
        'escopo' => 'todas',
        'dia_vencimento' => 5,
    ], comB: true))->assertSessionHasErrors(['rateio' => 'A parte de B já foi quitada. Exclua o pagamento para mudar valor ou divisão.']);

    expect(cotaDeB($parcelas[0])->carteira_id)->toBe(espelhoDe($this->nubank)->id);
});

it('RN-263 refuses the mass due day change that moves a settled part to another invoice', function () {
    $this->actingAs($this->user)->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-11-20', 'vencimento' => '2026-12-10'])
        ->assertSessionHasNoErrors();
    $parcelas = cursoRateado();
    pagarNoEspelho('145,00');

    $this->actingAs($this->user)->put(route('lancamentos.update', $parcelas[1]), dadosDeA([
        'descricao' => 'Curso',
        'valor' => '90,00',
        'data' => $parcelas[1]->data->toDateString(),
        'escopo' => 'todas',
        'dia_vencimento' => 25,
    ], comB: true))->assertSessionHasErrors(['dia_vencimento' => 'A parte de B já foi quitada. Exclua o pagamento para mudar valor ou divisão.']);

    expect($parcelas[0]->fresh()->data->toDateString())->toBe('2026-11-05');
});

it('CA-186.10 never dates an automatic receipt before the author payment that made the item', function () {
    pagarNoEspelho('130,00', mes: '2027-01', data: '2026-11-10');
    compraRateada($this->nubank, 'Bar', '80,00', '2026-11-21');

    pagarComB();

    expect(quitacoesDeB())->toBe([['2026-12', 'autor', 4000], ['2026-12', 'contato', 10000]])
        ->and(RecebimentoContato::query()->sole())->valor->toBe(3000)
        ->and(RecebimentoContato::query()->sole()->data->toDateString())->toBe('2026-11-14');
});

it('refuses to cut installments with a settled part, on the total field (RN-250, RN-258)', function () {
    $parcelas = cursoRateado();
    pagarNoEspelho('235,00', mes: '2027-02');

    $this->actingAs($this->user)->patch(route('parcelamentos.total', $parcelas[0]->parcelamento_id), ['total_parcelas' => 2])
        ->assertSessionHasErrors(['total_parcelas' => 'A parte de B já foi quitada. Exclua o pagamento para excluir a compra.']);

    expect(Lancamento::query()->where('descricao', 'Curso')->count())->toBe(3);
});
