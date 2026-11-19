<?php

use App\Actions\Cartoes\PagarFatura;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\Lancamento;
use App\Models\PagamentoFatura;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    cenarioCartao();
});

/**
 * @param  array<string, mixed>  $parametros
 */
function relatorioDe(Carteira $carteira, array $parametros = []): TestResponse
{
    return test()->actingAs(test()->user)->get(route('relatorio', ['carteira' => $carteira->id, ...$parametros]));
}

/**
 * A 3 × 100,00 installment series in the Nubank from 2026-11-05, created through the form.
 */
function serieNoNubank(): Lancamento
{
    test()->actingAs(test()->user)
        ->post(route('lancamentos.store'), dadosSerie(test()->nubank, test()->mercado, ['data' => '2026-11-05', 'valor' => '100,00', 'modo_valor' => 'parcela', 'total_parcelas' => 3]))
        ->assertSessionHasNoErrors();

    return Lancamento::query()->where('descricao', 'TV')->where('numero_parcela', 1)->sole();
}

it('CA-172.1 counts the invoice due in the month as expenses of the linked wallet, not the payment', function () {
    relatorioDe($this->x, ['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->where('resumo.despesas', 30000)
        ->where('resumo.receitas', 500000)
        ->where('despesasPorCategoria.ranking', fn ($ranking) => collect($ranking)->map(fn ($linha) => [$linha['nome'], $linha['valor']])->all() === [['Mercado', 30000]]));
});

it('CA-172.2 reports the card by the due date of its invoices', function () {
    relatorioDe($this->nubank, ['periodo' => '3m'])->assertInertia(fn (Assert $page) => $page
        ->where('resumo.despesas', 30000)
        ->where('despesasPorCategoria.ranking', fn ($ranking) => collect($ranking)->map(fn ($linha) => [$linha['nome'], $linha['valor']])->all() === [['Mercado', 30000]]));
});

it('CA-172.3 has no savings rate and shows the card debt in evolution and forecast', function () {
    relatorioDe($this->nubank, ['periodo' => '3m'])->assertInertia(fn (Assert $page) => $page
        ->where('resumo.taxaPoupanca', null)
        ->where('evolucao', fn ($meses) => collect($meses)->map(fn ($mes) => [$mes['mes'], $mes['despesas'], $mes['acumulado']])->all() === [
            ['2026-09', 0, 0],
            ['2026-10', 0, -30000],
            ['2026-11', 30000, -30000],
        ])
        ->where('previsao.meses.0.acumulado', -35000)
        ->where('previsao.primeiroNegativo', null));
});

it('CA-172.4 lists cards apart with the current and estimated debt', function () {
    relatorioDe($this->x)->assertInertia(fn (Assert $page) => $page
        ->where('cartoes', [[
            'id' => $this->nubank->id,
            'nome' => 'Nubank',
            'cor' => 'blue',
            'icone' => 'credit-card',
            'atual' => -20000,
            'estimado' => -30000,
        ]])
        ->where('carteiras', fn ($carteiras) => collect($carteiras)->pluck('id')->doesntContain($this->nubank->id)
            && collect($carteiras)->firstWhere('id', $this->x->id)['atual'] === 470000));
});

it('CA-172.5 lists a card series with installments of unsettled invoices as pending', function () {
    serieNoNubank();

    relatorioDe($this->nubank)->assertInertia(fn (Assert $page) => $page
        ->where('compromissos.parceladas.series.0.descricao', 'TV')
        ->where('compromissos.parceladas.series.0.parcelasPendentes', 3)
        ->where('compromissos.parceladas.series.0.restante', 30000));

    app(PagarFatura::class)->handle($this->nubank, CarbonImmutable::parse('2026-12-01'), 40000, '2026-11-14');

    relatorioDe($this->nubank)->assertInertia(fn (Assert $page) => $page
        ->where('compromissos.parceladas.series.0.parcelasPendentes', 2)
        ->where('compromissos.parceladas.series.0.restante', 20000));
});

it('CA-172.6 counts card income in the linked wallet on the due date', function () {
    compraNoCartao('2026-10-08', 5000, $this->entrada, 'receita');

    relatorioDe($this->x, ['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page->where('resumo.receitas', 505000));
});

it('CA-173.1 moves every purchase of a deleted card to a common wallet with status by date', function () {
    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->nubank), ['modo' => 'mover', 'destino_id' => $this->x->id])
        ->assertSessionHasNoErrors();

    $cotas = Cota::query()->whereIn('lancamento_id', [$this->compraOutubro->id, $this->compraDezembro->id])->orderBy('id')->get();

    expect($cotas->pluck('carteira_id')->unique()->all())->toBe([$this->x->id])
        ->and($cotas->pluck('fatura_id')->unique()->all())->toBe([null])
        ->and($cotas->pluck('executado')->all())->toBe([true, false])
        ->and(Fatura::query()->count())->toBe(0)
        ->and(PagamentoFatura::query()->count())->toBe(0)
        ->and(Cartao::query()->count())->toBe(0);
});

it('CA-173.2 moves the purchases of a deleted card into the invoices of another card', function () {
    $w = cartaoDe($this->user, 'W', diaVencimento: 20);

    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->nubank), ['modo' => 'mover', 'destino_id' => $w->id])
        ->assertSessionHasNoErrors();

    expect($this->compraOutubro->cotas()->sole()->fatura_id)->toBe(faturaDe($w, '2026-11')->id)
        ->and($this->compraNovembro->cotas()->sole()->fatura_id)->toBe(faturaDe($w, '2026-11')->id)
        ->and($this->compraFimNovembro->cotas()->sole()->fatura_id)->toBe(faturaDe($w, '2026-12')->id);
});

it('CA-173.3 deletes purchases, invoices, payments and the card row in the cascade', function () {
    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->nubank), ['modo' => 'excluir'])
        ->assertSessionHasNoErrors();

    expect(Lancamento::query()->whereKey($this->compraOutubro->id)->exists())->toBeFalse()
        ->and(Fatura::query()->count())->toBe(0)
        ->and(PagamentoFatura::query()->count())->toBe(0)
        ->and(Cartao::query()->count())->toBe(0);
});

it('CA-173.4 moves cards, payments and invoices of a deleted linked wallet to the destination', function () {
    $y = Carteira::factory()->for($this->user)->create(['nome' => 'Y']);

    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->x), ['modo' => 'mover', 'destino_id' => $y->id])
        ->assertSessionHasNoErrors();

    expect($this->nubank->cartao()->sole()->carteira_vinculada_id)->toBe($y->id)
        ->and($this->pagamento->fresh()->carteira_id)->toBe($y->id)
        ->and(Fatura::query()->pluck('carteira_vinculada_id')->unique()->all())->toBe([$y->id]);
});

it('CA-173.4 moves a common wallet only to another common wallet', function () {
    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->x), ['modo' => 'mover', 'destino_id' => $this->nubank->id])
        ->assertSessionHasErrors('destino_id');
});

it('CA-173.5 deletes the payments of a linked wallet deleted in cascade and makes the card independent', function () {
    $this->actingAs($this->user)
        ->delete(route('carteiras.destroy', $this->x), ['modo' => 'excluir'])
        ->assertSessionHasNoErrors();

    expect(PagamentoFatura::query()->count())->toBe(0)
        ->and($this->nubank->cartao()->sole()->carteira_vinculada_id)->toBeNull();
});

it('CA-175.1 puts each installment of a card series in the invoice of its date', function () {
    $primeira = serieNoNubank();

    $faturas = Cota::query()
        ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
        ->where('lancamentos.parcelamento_id', $primeira->parcelamento_id)
        ->orderBy('lancamentos.numero_parcela')
        ->pluck('cotas.fatura_id')
        ->all();

    expect($faturas)->toBe([faturaDe($this->nubank, '2026-12')->id, faturaDe($this->nubank, '2027-01')->id, faturaDe($this->nubank, '2027-02')->id]);
});

it('CA-175.2 dates card installments by the due date and pays them with the settled invoice', function () {
    $primeira = serieNoNubank();
    app(PagarFatura::class)->handle($this->nubank, CarbonImmutable::parse('2026-12-01'), 40000, '2026-11-14');

    $this->actingAs($this->user)
        ->get(route('parcelamentos.show', $primeira->parcelamento_id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('parcelas', fn ($parcelas) => collect($parcelas)->map(fn ($parcela) => [$parcela['data'], $parcela['dataCompra'], $parcela['executado']])->all() === [
                ['2026-12-10', '2026-11-05', true],
                ['2027-01-10', '2026-12-05', false],
                ['2027-02-10', '2027-01-05', false],
            ])
            ->where('resumo.pagas', 1));
});
