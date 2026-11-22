<?php

use App\Enums\CompetenciaFatura;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\PagamentoFatura;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    cenarioCartao();
    competenciaDoNubank(CompetenciaFatura::Fechamento);
});

function competenciaDoNubank(CompetenciaFatura $competencia): void
{
    test()->nubank->cartao()->update(['competencia' => $competencia]);
}

/**
 * @param  array<string, mixed>  $parametros
 */
function telaDaCompetencia(Carteira $carteira, array $parametros = []): TestResponse
{
    return test()->actingAs(test()->user)->get(route('home', ['carteira' => $carteira->id, ...$parametros]));
}

/**
 * Stored invoices, shares and payments of the scenario, to prove a competence change touches no data.
 *
 * @return array<string, mixed>
 */
function dadosDasFaturas(): array
{
    return [
        'faturas' => Fatura::query()->orderBy('id')->get(['id', 'referencia', 'fechamento', 'vencimento', 'carteira_vinculada_id', 'datas_editadas'])->toArray(),
        'cotas' => Cota::query()->orderBy('id')->get(['id', 'fatura_id', 'valor', 'carteira_id'])->toArray(),
        'pagamentos' => PagamentoFatura::query()->orderBy('id')->get(['id', 'fatura_id', 'valor', 'data', 'carteira_id'])->toArray(),
    ];
}

it('CA-177.1 names the invoice by the closing month, or by the due month', function (CompetenciaFatura $competencia, string $mes) {
    competenciaDoNubank($competencia);

    telaDaCompetencia($this->nubank, ['mes' => $mes])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.vencimento', '2026-12-10')
        ->where('fatura.competencia', $mes)
        ->where('fatura.referencia', '2026-12'));
})->with([
    'closing' => [CompetenciaFatura::Fechamento, '2026-11'],
    'due' => [CompetenciaFatura::Vencimento, '2026-12'],
]);

it('CA-177.2 shows the invoice of the competence month and walks invoice by invoice', function (string $mes, string $vencimento) {
    telaDaCompetencia($this->nubank, ['mes' => $mes])->assertInertia(fn (Assert $page) => $page
        ->where('mes', $mes)
        ->where('fatura.vencimento', $vencimento));
})->with([
    'November' => ['2026-11', '2026-12-10'],
    'previous' => ['2026-10', '2026-11-10'],
    'next' => ['2026-12', '2027-01-10'],
]);

it('CA-177.3 opens the card on the competence of the open invoice', function () {
    telaDaCompetencia($this->nubank)->assertInertia(fn (Assert $page) => $page
        ->where('mes', '2026-11')
        ->missing('mesHoje')
        ->where('fatura.estado', 'aberta')
        ->where('fatura.vencimento', '2026-12-10'));
});

it('CA-177.4 keeps the due month when the default closing falls in it', function () {
    $inter = cartaoDe($this->user, 'Inter', $this->x, diaVencimento: 15, diasFechamento: 10, competencia: CompetenciaFatura::Fechamento);

    telaDaCompetencia($inter, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.vencimento', '2026-12-15')
        ->where('fatura.fechamento', '2026-12-05')
        ->where('fatura.competencia', '2026-12'));
});

it('CA-177.5 keeps the competence of an invoice whose closing was edited into the next month', function () {
    $this->actingAs($this->user)
        ->put(route('faturas.datas', [$this->nubank, '2026-12']), ['fechamento' => '2026-12-02', 'vencimento' => '2026-12-10'])
        ->assertSessionHasNoErrors();

    telaDaCompetencia($this->nubank, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.fechamento', '2026-12-02')
        ->where('fatura.vencimento', '2026-12-10')
        ->where('fatura.competencia', '2026-11'));
    telaDaCompetencia($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.vencimento', '2027-01-10'));
});

it('CA-177.6 keeps the linked wallet row on the due month and opens the card on the competence', function () {
    $saldosPorRegra = [];

    foreach ([CompetenciaFatura::Vencimento, CompetenciaFatura::Fechamento] as $competencia) {
        competenciaDoNubank($competencia);

        telaDaCompetencia($this->x, ['mes' => '2026-12'])->assertInertia(function (Assert $page) use ($competencia, &$saldosPorRegra) {
            $page->has('faturasVinculadas', 1)
                ->where('faturasVinculadas.0.vencimento', '2026-12-10')
                ->where('faturasVinculadas.0.mes', $competencia === CompetenciaFatura::Fechamento ? '2026-11' : '2026-12');
            $saldosPorRegra[] = $page->toArray()['props']['saldos'];
        });
        $this->actingAs($this->user)->get(route('relatorio', ['carteira' => $this->x->id, 'periodo' => '3m']))
            ->assertInertia(function (Assert $page) use (&$saldosPorRegra) {
                $props = $page->toArray()['props'];
                unset($props['auth'], $props['errors'], $props['flash']);
                $saldosPorRegra[] = $props;
            });
    }

    expect($saldosPorRegra[0])->toBe($saldosPorRegra[2])
        ->and($saldosPorRegra[1])->toBe($saldosPorRegra[3]);
});

it('CA-177.7 shows in the card tile the to pay of the invoice of the competence month', function () {
    telaDaCompetencia($this->x, ['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where("destaques.saldosCarteiras.{$this->nubank->id}", 30000));
});

it('CA-177.8 changes only the name when the competence rule changes', function () {
    $antes = dadosDasFaturas();

    $this->actingAs($this->user)
        ->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 10, 'dias_fechamento' => 10, 'competencia' => 'vencimento', 'carteira_vinculada_id' => $this->x->id])
        ->assertSessionHasNoErrors();

    expect($this->nubank->cartao()->sole()->competencia)->toBe(CompetenciaFatura::Vencimento)
        ->and(dadosDasFaturas())->toBe($antes);
    telaDaCompetencia($this->nubank, ['mes' => '2026-12'])->assertInertia(fn (Assert $page) => $page
        ->where('fatura.vencimento', '2026-12-10')
        ->where('fatura.competencia', '2026-12'));
});

it('CA-177.8 rejects an unknown competence rule', function () {
    $this->actingAs($this->user)
        ->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 10, 'competencia' => 'meio'])
        ->assertSessionHasErrors('competencia');

    expect($this->nubank->cartao()->sole()->competencia)->toBe(CompetenciaFatura::Fechamento);
});

it('RN-253 keeps the competence rule when the field is missing on update', function () {
    competenciaDoNubank(CompetenciaFatura::Vencimento);

    $this->actingAs($this->user)
        ->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 10, 'carteira_vinculada_id' => $this->x->id])
        ->assertSessionHasNoErrors();

    expect($this->nubank->cartao()->sole()->competencia)->toBe(CompetenciaFatura::Vencimento);
});

it('RN-253 never changes the competence rule in simple mode', function () {
    $this->user->update(['modo_simples' => true]);

    $this->actingAs($this->user)
        ->put(route('carteiras.update', $this->nubank), ['nome' => 'Nubank', 'dia_vencimento' => 10, 'competencia' => 'vencimento', 'carteira_vinculada_id' => $this->x->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)
        ->post(route('carteiras.store'), ['nome' => 'Inter', 'tipo' => 'cartao', 'dia_vencimento' => 5, 'competencia' => 'vencimento', 'carteira_vinculada_id' => $this->x->id])
        ->assertSessionHasNoErrors();

    expect($this->nubank->cartao()->sole()->competencia)->toBe(CompetenciaFatura::Fechamento)
        ->and($this->user->carteiras()->where('nome', 'Inter')->sole()->cartao->competencia)->toBe(CompetenciaFatura::Fechamento);
});

it('CA-177.9 keeps the card report amounts with both rules', function () {
    $relatorios = [];

    foreach ([CompetenciaFatura::Vencimento, CompetenciaFatura::Fechamento] as $competencia) {
        competenciaDoNubank($competencia);

        $this->actingAs($this->user)->get(route('relatorio', ['carteira' => $this->nubank->id, 'periodo' => '3m']))
            ->assertInertia(function (Assert $page) use (&$relatorios) {
                $props = $page->toArray()['props'];
                unset($props['auth'], $props['errors'], $props['flash']);
                $relatorios[] = $props;
            });
    }

    expect($relatorios[0])->toBe($relatorios[1]);
});
