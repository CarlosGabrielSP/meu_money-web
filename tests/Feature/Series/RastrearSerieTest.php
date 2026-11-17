<?php

use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->user = User::factory()->create();
    $this->x = Carteira::factory()->for($this->user)->padrao()->create(['nome' => 'X']);
    $this->y = Carteira::factory()->for($this->user)->create(['nome' => 'Y']);
    $this->categoria = Categoria::factory()->for($this->user)->create(['nome' => 'Eletrônicos']);
});

/**
 * Series of the given author with one installment per item: [number, date, amount, executed] for
 * the author's share. Installments missing from the list were deleted (RN-057).
 *
 * @param  list<array{0: int, 1: string, 2: int, 3: bool}>  $parcelas
 */
function serieRastreada(User $autor, ?int $totalParcelas, array $parcelas, ?Carteira $carteira = null): Parcelamento
{
    $carteira ??= $autor->carteiras()->firstOrFail();
    $categoria = Categoria::query()->where('user_id', $autor->id)->firstOrFail();

    $serie = Parcelamento::factory()->for($autor)->create([
        'carteira_id' => $carteira->id,
        'categoria_id' => $categoria->id,
        'descricao' => 'TV',
        'total_parcelas' => $totalParcelas,
    ]);

    foreach ($parcelas as [$numero, $data, $valor, $executado]) {
        lancamentoComCota($autor, $carteira, $categoria, [
            'descricao' => 'TV',
            'data' => $data,
            'valor' => $valor,
            'parcelamento_id' => $serie->id,
            'numero_parcela' => $numero,
        ], ['executado' => $executado]);
    }

    return $serie;
}

function rastreio(Parcelamento $serie, ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? test()->user)->get(route('parcelamentos.show', $serie));
}

/**
 * Sample data of RF-151: TV, 10 × 150,00 since 2026-08-25, 1 to 3 executed, 5 deleted, 7 in Y.
 */
function serieTv(): Parcelamento
{
    $parcelas = [];
    foreach ([1, 2, 3, 4, 6, 7, 8, 9, 10] as $numero) {
        $data = CarbonImmutable::parse('2026-08-25')->addMonthsNoOverflow($numero - 1)->toDateString();
        $parcelas[] = [$numero, $data, 15000, $numero <= 3];
    }

    $serie = serieRastreada(test()->user, 10, $parcelas);
    Cota::query()
        ->whereHas('lancamento', fn ($lancamento) => $lancamento->where('parcelamento_id', $serie->id)->where('numero_parcela', 7))
        ->update(['carteira_id' => test()->y->id]);

    return $serie;
}

it('CA-150.1 opens the tracking of a series with a share of the user', function () {
    $serie = serieTv();

    rastreio($serie)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('parcelamentos/Show')
        ->where('serie.id', $serie->id)
        ->where('serie.descricao', 'TV')
        ->where('serie.tipo', 'despesa')
        ->where('serie.periodicidade', 'mensal')
        ->where('serie.totalParcelas', 10)
        ->where('serie.categoria.nome', 'Eletrônicos'));
});

it('CA-150.2 forbids a series without any share of the user', function () {
    $outro = User::factory()->create();
    Carteira::factory()->for($outro)->padrao()->create();
    Categoria::factory()->for($outro)->create();
    $serie = serieRastreada($outro, 3, [[1, '2026-11-20', 10000, false]]);

    rastreio($serie)->assertForbidden();
});

it('CA-150.3 CA-151.4 shows only the shares of the participant in a split series', function () {
    $autor = User::factory()->create();
    Carteira::factory()->for($autor)->padrao()->create();
    Categoria::factory()->for($autor)->create();
    $serie = serieRastreada($autor, 2, [[1, '2026-10-20', 5000, true], [2, '2026-11-20', 5000, false]]);

    foreach ($serie->lancamentos as $parcela) {
        $parcela->update(['valor' => 10000]);
        Cota::factory()->for($parcela)->create([
            'user_id' => $this->user->id,
            'carteira_id' => $this->x->id,
            'categoria_id' => $this->categoria->id,
            'valor' => 5000,
            'executado' => $parcela->numero_parcela === 1,
        ]);
    }

    rastreio($serie)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('resumo', ['total' => 10000, 'pago' => 5000, 'pendente' => 5000, 'pagas' => 1, 'existentes' => 2])
        ->where('parcelas.0.valor', 5000)
        ->where('parcelas.0.carteira.nome', 'X')
        ->where('serie.categoria.nome', 'Eletrônicos'));
});

it('CA-150.4 goes back to the page of the app the user came from, or home', function (?string $origem, string $destino) {
    $serie = serieTv();
    $cabecalhos = $origem === null ? [] : ['Referer' => $origem];

    $this->actingAs($this->user)
        ->get(route('parcelamentos.show', $serie), $cabecalhos)
        ->assertInertia(fn (Assert $page) => $page->where('voltarPara', $destino));
})->with([
    'page of the app' => [fn () => route('relatorio', ['periodo' => '3m']), fn () => route('relatorio', ['periodo' => '3m'])],
    'no origin' => [null, fn () => route('home')],
    'another site' => ['https://exemplo.com/pagina', fn () => route('home')],
]);

it('CA-151.1 sums the paid and pending shares over the existing installments', function () {
    rastreio(serieTv())->assertInertia(fn (Assert $page) => $page
        ->where('resumo', ['total' => 135000, 'pago' => 45000, 'pendente' => 90000, 'pagas' => 3, 'existentes' => 9]));
});

it('CA-151.2 lists the installments by number with the gap of the deleted one', function () {
    $serie = serieTv();
    $this->travelTo(CarbonImmutable::parse('2026-11-30 10:00'));

    rastreio($serie)->assertInertia(fn (Assert $page) => $page
        ->has('parcelas', 9)
        ->where('parcelas', fn ($parcelas) => collect($parcelas)->pluck('numero')->all() === [1, 2, 3, 4, 6, 7, 8, 9, 10])
        ->where('parcelas.0', [
            'numero' => 1,
            'data' => '2026-08-25',
            'carteira' => ['nome' => 'X', 'cor' => $this->x->cor->value],
            'valor' => 15000,
            'executado' => true,
            'vencida' => false,
        ])
        ->where('parcelas.3.numero', 4)
        ->where('parcelas.3.vencida', true)
        ->where('parcelas.4.vencida', false)
        ->where('parcelas.5.numero', 7)
        ->where('parcelas.5.carteira.nome', 'Y'));
});

it('CA-151.3 has no total for a recurring series', function () {
    $parcelas = [];
    foreach (range(1, 15) as $numero) {
        $data = CarbonImmutable::parse('2026-08-10')->addMonthsNoOverflow($numero - 1)->toDateString();
        $parcelas[] = [$numero, $data, 150000, $numero <= 3];
    }

    $serie = serieRastreada($this->user, null, $parcelas);

    rastreio($serie)->assertInertia(fn (Assert $page) => $page
        ->where('serie.totalParcelas', null)
        ->where('serie.recorrente', true)
        ->where('resumo', ['total' => null, 'pago' => 450000, 'pendente' => 1800000, 'pagas' => 3, 'existentes' => 15])
        ->has('parcelas', 15));
});

it('CA-151.5 keeps the number of queries constant as installments grow', function () {
    $consultas = function (Parcelamento $serie): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        rastreio($serie)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $parcelas = fn (int $quantidade): array => array_map(
        fn (int $numero): array => [$numero, CarbonImmutable::parse('2026-01-10')->addMonthsNoOverflow($numero - 1)->toDateString(), 1000, false],
        range(1, $quantidade),
    );

    expect($consultas(serieRastreada($this->user, 12, $parcelas(12))))
        ->toBe($consultas(serieRastreada($this->user, 120, $parcelas(120))));
});
