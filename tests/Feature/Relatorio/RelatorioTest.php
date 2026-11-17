<?php

use App\Enums\Periodicidade;
use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->user = User::factory()->create();
    $this->principal = Carteira::factory()->for($this->user)->padrao()->create(['nome' => 'Principal']);
    $this->x = Carteira::factory()->for($this->user)->create(['nome' => 'X']);
    $this->categoria = Categoria::factory()->for($this->user)->create(['tipo' => TipoCategoria::Ambos]);
});

/**
 * One share of the user in the wallet (wallet X when omitted) with the given date, type, amount in
 * cents and status.
 *
 * @param  array<string, mixed>  $lancamento
 */
function cotaNoRelatorio(string $data, string $tipo, int $valor, bool $executado = false, ?Carteira $carteira = null, ?Categoria $categoria = null, array $lancamento = []): Lancamento
{
    return lancamentoComCota(
        test()->user,
        $carteira ?? test()->x,
        $categoria ?? test()->categoria,
        ['data' => $data, 'tipo' => $tipo, 'valor' => $valor, ...$lancamento],
        ['executado' => $executado],
    );
}

/**
 * A series of the user in wallet X with one share per installment: [date, amount, executed].
 *
 * @param  list<array{0: string, 1: int, 2: bool}>  $parcelas
 */
function serieNoRelatorio(string $descricao, ?int $totalParcelas, Periodicidade $periodicidade, array $parcelas, string $tipo = 'despesa'): Parcelamento
{
    $serie = Parcelamento::factory()->for(test()->user)->create([
        'carteira_id' => test()->x->id,
        'categoria_id' => test()->categoria->id,
        'descricao' => $descricao,
        'tipo' => $tipo,
        'total_parcelas' => $totalParcelas,
        'periodicidade' => $periodicidade,
    ]);

    foreach ($parcelas as $indice => [$data, $valor, $executado]) {
        cotaNoRelatorio($data, $tipo, $valor, $executado, lancamento: [
            'descricao' => $descricao,
            'parcelamento_id' => $serie->id,
            'numero_parcela' => $indice + 1,
        ]);
    }

    return $serie;
}

/**
 * @param  array<string, mixed>  $parametros
 */
function relatorioDeX(array $parametros = []): TestResponse
{
    return test()->actingAs(test()->user)->get(route('relatorio', ['carteira' => test()->x->id, ...$parametros]));
}

/**
 * Sample data of RF-141, also used by RF-142.
 */
function exemploResumo(): void
{
    $x = test()->x;
    $contato = User::factory()->create();

    cotaNoRelatorio('2026-06-05', 'receita', 400000, true);
    cotaNoRelatorio('2026-07-10', 'despesa', 200000, true);
    cotaNoRelatorio('2026-09-05', 'receita', 500000, true);
    cotaNoRelatorio('2026-10-10', 'despesa', 150000, true);

    $rateada = cotaNoRelatorio('2026-10-15', 'despesa', 20000, lancamento: ['carteira_id' => $x->id]);
    $rateada->cotas()->update(['valor' => 10000]);
    Cota::factory()->for($rateada)->create(['user_id' => $contato->id, 'valor' => 10000]);

    $semCota = Lancamento::factory()->for(test()->user)->create(['data' => '2026-10-20', 'valor' => 30000, 'carteira_id' => $x->id]);
    Cota::factory()->for($semCota)->create(['user_id' => $contato->id, 'valor' => 30000]);

    cotaNoRelatorio('2026-11-05', 'receita', 500000, true);
    cotaNoRelatorio('2026-11-25', 'despesa', 100000);
    cotaNoRelatorio('2026-12-05', 'receita', 999900);
    cotaNoRelatorio('2026-10-01', 'receita', 77700, carteira: Carteira::factory()->for(test()->user)->create(['nome' => 'Y']));
}

it('CA-140.1 opens the default wallet in 6 months without a last used wallet', function () {
    $this->actingAs($this->user)->get(route('relatorio'))->assertInertia(fn (Assert $page) => $page
        ->component('Relatorio')
        ->where('carteira.id', $this->principal->id)
        ->where('periodo', '6m')
        ->where('intervalo', ['inicio' => '2026-06', 'fim' => '2026-11']));
});

it('CA-140.2 opens the last used wallet without parameters', function () {
    $this->user->forceFill(['ultima_carteira_id' => $this->x->id])->save();

    $this->actingAs($this->user)->get(route('relatorio'))->assertInertia(fn (Assert $page) => $page
        ->where('carteira.id', $this->x->id));
});

it('CA-140.3 opens the wallet and period of the URL and saves the wallet as last used', function () {
    relatorioDeX(['periodo' => '12m'])->assertInertia(fn (Assert $page) => $page
        ->where('carteira.id', $this->x->id)
        ->where('periodo', '12m')
        ->where('intervalo', ['inicio' => '2025-12', 'fim' => '2026-11']));

    expect($this->user->fresh()->ultima_carteira_id)->toBe($this->x->id);
});

it('CA-140.4 forbids a wallet of another user', function () {
    $outra = Carteira::factory()->for(User::factory())->create();

    $this->actingAs($this->user)->get(route('relatorio', ['carteira' => $outra->id]))->assertForbidden();
});

it('CA-140.5 falls back to 6 months for an invalid period', function () {
    relatorioDeX(['periodo' => '7m'])->assertInertia(fn (Assert $page) => $page->where('periodo', '6m'));
});

it('CA-141.1 sums the period and compares it with the previous one', function () {
    exemploResumo();

    relatorioDeX(['periodo' => '3m'])->assertInertia(fn (Assert $page) => $page
        ->where('intervalo', ['inicio' => '2026-09', 'fim' => '2026-11'])
        ->where('resumo', [
            'receitas' => 1000000,
            'despesas' => 260000,
            'resultado' => 740000,
            'taxaPoupanca' => 74,
            'variacoes' => ['receitas' => 150, 'despesas' => 30, 'resultado' => 270],
        ]));
});

it('CA-141.2 sends no change for the current year when the previous year has no data', function () {
    exemploResumo();

    relatorioDeX(['periodo' => 'ano'])->assertInertia(fn (Assert $page) => $page
        ->where('intervalo', ['inicio' => '2026-01', 'fim' => '2026-12'])
        ->where('resumo', [
            'receitas' => 2399900,
            'despesas' => 460000,
            'resultado' => 1939900,
            'taxaPoupanca' => 80.8,
            'variacoes' => ['receitas' => null, 'despesas' => null, 'resultado' => null],
        ]));
});

it('CA-141.3 sends no savings rate for a period without income', function () {
    cotaNoRelatorio('2026-11-05', 'despesa', 10000);

    relatorioDeX(['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->where('resumo.taxaPoupanca', null)
        ->where('resumo.resultado', -10000));
});

it('CA-141.4 compares against the absolute value of a negative previous result', function () {
    cotaNoRelatorio('2026-10-10', 'despesa', 100000);
    cotaNoRelatorio('2026-11-05', 'receita', 50000);

    relatorioDeX(['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->where('resumo.resultado', 50000)
        ->where('resumo.variacoes.resultado', 150));
});

it('CA-142.1 sends the income, expenses and accumulated balance of each month', function () {
    exemploResumo();

    relatorioDeX(['periodo' => '3m'])->assertInertia(fn (Assert $page) => $page
        ->where('evolucao', [
            ['mes' => '2026-09', 'receitas' => 500000, 'despesas' => 0, 'acumulado' => 700000],
            ['mes' => '2026-10', 'receitas' => 0, 'despesas' => 160000, 'acumulado' => 540000],
            ['mes' => '2026-11', 'receitas' => 500000, 'despesas' => 100000, 'acumulado' => 940000],
        ]));
});

it('CA-142.2 sends only the current month for this month', function () {
    exemploResumo();

    relatorioDeX(['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->has('evolucao', 1)
        ->where('evolucao.0.mes', '2026-11'));
});

/**
 * Category sample data of RF-143 with the system categories.
 */
function exemploCategorias(): void
{
    test()->seed(CategoriasGlobaisSeeder::class);
    $categoria = fn (string $nome): Categoria => Categoria::query()->globais()->where('nome', $nome)->sole();

    foreach ([
        ['Moradia', '2026-11-10', 'despesa', 150000],
        ['Mercado', '2026-11-03', 'despesa', 40000],
        ['Mercado', '2026-11-20', 'despesa', 30000],
        ['Lazer', '2026-11-08', 'despesa', 20000],
        ['Saúde', '2026-11-09', 'despesa', 15000],
        ['Transporte', '2026-11-11', 'despesa', 12000],
        ['Educação', '2026-11-12', 'despesa', 10000],
        ['Contas', '2026-11-13', 'despesa', 8000],
        ['Vestuário', '2026-11-14', 'despesa', 5000],
        ['Salário', '2026-11-05', 'receita', 500000],
        ['Moradia', '2026-10-10', 'despesa', 150000],
        ['Mercado', '2026-10-03', 'despesa', 50000],
    ] as [$nome, $data, $tipo, $valor]) {
        cotaNoRelatorio($data, $tipo, $valor, categoria: $categoria($nome));
    }
}

it('CA-143.1 ranks the expenses by category with share, change and icon', function () {
    exemploCategorias();

    relatorioDeX(['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->where('despesasPorCategoria.ranking', fn ($ranking) => collect($ranking)
            ->map(fn ($categoria) => [$categoria['nome'], $categoria['icone'], $categoria['valor'], $categoria['percentual'], $categoria['variacao']])
            ->all() === [
                ['Moradia', 'house', 150000, 51.7, 0],
                ['Mercado', 'shopping-cart', 70000, 24.1, 40],
                ['Lazer', 'gamepad-2', 20000, 6.9, null],
                ['Saúde', 'heart-pulse', 15000, 5.2, null],
                ['Transporte', 'car', 12000, 4.1, null],
                ['Educação', 'graduation-cap', 10000, 3.4, null],
                ['Contas', 'receipt', 8000, 2.8, null],
                ['Vestuário', 'shirt', 5000, 1.7, null],
            ]));
});

it('CA-143.2 groups the categories after the sixth as "Outras" in the donut', function () {
    exemploCategorias();

    relatorioDeX(['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->has('despesasPorCategoria.rosca', 7)
        ->where('despesasPorCategoria.rosca.5', ['nome' => 'Educação', 'valor' => 10000, 'outras' => false])
        ->where('despesasPorCategoria.rosca.6', ['nome' => 'Outras', 'valor' => 13000, 'outras' => true]));
});

it('CA-143.3 has no "Outras" with up to 6 categories', function () {
    exemploCategorias();

    Cota::query()->whereHas('categoria', fn ($categoria) => $categoria->whereIn('nome', ['Contas', 'Vestuário']))->delete();

    relatorioDeX(['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->has('despesasPorCategoria.rosca', 6)
        ->where('despesasPorCategoria.rosca', fn ($rosca) => collect($rosca)->every(fn ($fatia) => $fatia['outras'] === false)));
});

it('CA-144.1 splits the expenses into one-off, installments and recurring', function () {
    cotaNoRelatorio('2026-11-03', 'despesa', 60000);
    $agrupada = cotaNoRelatorio('2026-11-04', 'despesa', 40000);
    $agrupada->cotas()->update(['grupo_id' => Grupo::factory()->for($this->user)->create(['carteira_id' => $this->x->id])->id]);
    serieNoRelatorio('TV', 10, Periodicidade::Mensal, [['2026-11-25', 15000, false]]);
    serieNoRelatorio('Aluguel', null, Periodicidade::Mensal, [['2026-11-10', 150000, false]]);

    relatorioDeX(['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->where('despesasPorTipo', [
            ['tipo' => 'avista', 'valor' => 100000, 'percentual' => 37.7],
            ['tipo' => 'parceladas', 'valor' => 15000, 'percentual' => 5.7],
            ['tipo' => 'recorrentes', 'valor' => 150000, 'percentual' => 56.6],
        ]));
});

/**
 * Forecast sample data of RF-145.
 */
function exemploPrevisao(): void
{
    cotaNoRelatorio('2026-11-05', 'receita', 200000, true);
    cotaNoRelatorio('2026-12-05', 'receita', 500000);
    cotaNoRelatorio('2026-12-10', 'despesa', 300000);
    cotaNoRelatorio('2027-01-10', 'despesa', 600000);
    cotaNoRelatorio('2027-02-05', 'receita', 100000);
    cotaNoRelatorio('2027-06-10', 'despesa', 50000);
}

it('CA-145.1 CA-145.3 forecasts the next 6 months regardless of the period', function (string $periodo) {
    exemploPrevisao();

    relatorioDeX(['periodo' => $periodo])->assertInertia(fn (Assert $page) => $page
        ->where('previsao', [
            'meses' => [
                ['mes' => '2026-12', 'resultado' => 200000, 'acumulado' => 400000],
                ['mes' => '2027-01', 'resultado' => -600000, 'acumulado' => -200000],
                ['mes' => '2027-02', 'resultado' => 100000, 'acumulado' => -100000],
                ['mes' => '2027-03', 'resultado' => 0, 'acumulado' => -100000],
                ['mes' => '2027-04', 'resultado' => 0, 'acumulado' => -100000],
                ['mes' => '2027-05', 'resultado' => 0, 'acumulado' => -100000],
            ],
            'primeiroNegativo' => '2027-01',
        ]));
})->with(['6m', '3m', 'ano']);

it('CA-145.2 has no first negative month when the balance stays positive', function () {
    cotaNoRelatorio('2026-11-05', 'receita', 200000, true);
    cotaNoRelatorio('2027-01-10', 'despesa', 50000);

    relatorioDeX()->assertInertia(fn (Assert $page) => $page->where('previsao.primeiroNegativo', null));
});

/**
 * Commitments sample data of RF-146 in wallet X.
 */
function exemploCompromissos(): void
{
    $contato = User::factory()->create();

    serieNoRelatorio('TV', 10, Periodicidade::Mensal, array_map(
        fn (int $indice) => [CarbonImmutable::parse('2026-08-25')->addMonths($indice)->toDateString(), 15000, $indice < 3],
        range(0, 9),
    ));
    serieNoRelatorio('Curso', 3, Periodicidade::Mensal, [['2026-07-01', 20000, true], ['2026-08-01', 20000, true], ['2026-09-01', 20000, true]]);
    serieNoRelatorio('Aluguel', null, Periodicidade::Mensal, [
        ['2026-10-10', 150000, true], ['2026-11-10', 150000, false], ['2026-12-10', 150000, false],
    ]);
    serieNoRelatorio('IPVA', null, Periodicidade::Anual, [['2026-03-15', 120000, true], ['2027-03-15', 120000, false]]);
    serieNoRelatorio('Academia', null, Periodicidade::Mensal, [['2026-09-10', 10000, true], ['2026-10-10', 10000, false]])
        ->forceFill(['encerrado_em' => '2026-10-31'])->save();
    serieNoRelatorio('Aluguel recebido', null, Periodicidade::Mensal, [['2026-11-20', 80000, false]], tipo: 'receita');

    $internet = serieNoRelatorio('Internet', null, Periodicidade::Mensal, [['2026-11-20', 10000, false], ['2026-12-20', 10000, false]]);
    foreach ($internet->lancamentos as $parcela) {
        $parcela->cotas()->update(['valor' => 5000]);
        Cota::factory()->for($parcela)->create(['user_id' => $contato->id, 'valor' => 5000]);
    }
}

it('CA-146.1 lists the installment series with pending installments', function () {
    exemploCompromissos();

    relatorioDeX()->assertInertia(fn (Assert $page) => $page
        ->where('compromissos.parceladas.series', fn ($series) => collect($series)
            ->map(fn ($serie) => [$serie['descricao'], $serie['parcelasPendentes'], $serie['restante'], $serie['ultimaParcela']])
            ->all() === [['TV', 7, 105000, '2027-05']])
        ->where('compromissos.parceladas.total', 105000));
});

it('CA-146.2 lists the active recurring series with their monthly equivalent', function () {
    exemploCompromissos();

    relatorioDeX()->assertInertia(fn (Assert $page) => $page
        ->where('compromissos.recorrentes.series', fn ($series) => collect($series)
            ->mapWithKeys(fn ($serie) => [$serie['descricao'] => [$serie['proximoValor'], $serie['periodicidade'], $serie['mensal']]])
            ->all() == [
                'Aluguel' => [150000, 'mensal', 150000],
                'Internet' => [5000, 'mensal', 5000],
                'IPVA' => [120000, 'anual', 10000],
            ])
        ->where('compromissos.recorrentes.total', 165000));
});

it('CA-146.3 rounds the monthly equivalent down to the cent', function () {
    serieNoRelatorio('Revista', null, Periodicidade::Bimestral, [['2026-12-01', 9999, false]]);

    relatorioDeX()->assertInertia(fn (Assert $page) => $page
        ->where('compromissos.recorrentes.series.0.mensal', 4999)
        ->where('compromissos.recorrentes.total', 4999));
});

/**
 * Wallet sample data of RF-147: X and Y with shares, Z without.
 *
 * @return array{y: Carteira, z: Carteira}
 */
function exemploCarteiras(): array
{
    $y = Carteira::factory()->for(test()->user)->create(['nome' => 'Y']);
    $z = Carteira::factory()->for(test()->user)->create(['nome' => 'Z']);

    cotaNoRelatorio('2026-11-01', 'receita', 100000, true);
    cotaNoRelatorio('2026-11-20', 'despesa', 30000);
    cotaNoRelatorio('2026-12-05', 'receita', 50000);
    cotaNoRelatorio('2026-10-01', 'receita', 20000, true, carteira: $y);

    return ['y' => $y, 'z' => $z];
}

it('CA-147.1 CA-147.2 sends the current and estimated balance of every wallet regardless of the period', function (string $periodo) {
    ['y' => $y, 'z' => $z] = exemploCarteiras();

    relatorioDeX(['periodo' => $periodo])->assertInertia(fn (Assert $page) => $page
        ->where('carteiras', fn ($carteiras) => collect($carteiras)
            ->map(fn ($carteira) => [$carteira['id'], $carteira['atual'], $carteira['estimado']])
            ->all() === [
                [$this->principal->id, 0, 0],
                [$this->x->id, 100000, 70000],
                [$y->id, 20000, 20000],
                [$z->id, 0, 0],
            ])
        ->where('carteira.id', $this->x->id));
})->with(['6m', '3m', 'ano']);

it('CA-147.3 keeps the number of queries constant as wallets, entries and months grow', function () {
    $consultas = function (string $periodo): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        relatorioDeX(['periodo' => $periodo])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $this->user->forceFill(['ultima_carteira_id' => $this->x->id])->save();
    cotaNoRelatorio('2026-11-02', 'receita', 100);
    $comUma = $consultas('mes');

    foreach (range(1, 4) as $indice) {
        cotaNoRelatorio('2026-11-02', 'receita', 100, true, carteira: Carteira::factory()->for($this->user)->create());
        cotaNoRelatorio("2026-0{$indice}-10", 'despesa', 100);
        serieNoRelatorio("Série {$indice}", $indice % 2 === 0 ? null : 5, Periodicidade::Mensal, [['2026-12-10', 100, false]]);
    }

    expect($consultas('12m'))->toBe($comUma);
});

it('CA-148.1 sums what the user split and what was split with the user in the wallet', function () {
    $contato = User::factory()->create();
    $y = Carteira::factory()->for($this->user)->create(['nome' => 'Y']);

    $dividido = cotaNoRelatorio('2026-11-10', 'despesa', 30000, lancamento: ['carteira_id' => $this->x->id]);
    $dividido->cotas()->update(['valor' => 15000]);
    Cota::factory()->for($dividido)->create(['user_id' => $contato->id, 'valor' => 15000]);

    $presente = Lancamento::factory()->for($this->user)->create(['data' => '2026-11-12', 'valor' => 10000, 'carteira_id' => $this->x->id]);
    Cota::factory()->for($presente)->create(['user_id' => $contato->id, 'valor' => 10000]);

    $emY = cotaNoRelatorio('2026-11-11', 'despesa', 40000, carteira: $y, lancamento: ['carteira_id' => $y->id]);
    Cota::factory()->for($emY)->create(['user_id' => $contato->id, 'valor' => 20000]);

    foreach ([['2026-11-15', 8000], ['2026-10-15', 4000]] as [$data, $valor]) {
        $doContato = Lancamento::factory()->for($contato)->create(['data' => $data, 'valor' => $valor * 2]);
        Cota::factory()->for($doContato)->create(['user_id' => $this->user->id, 'carteira_id' => $this->x->id, 'categoria_id' => $this->categoria->id, 'valor' => $valor]);
    }

    relatorioDeX(['periodo' => 'mes'])->assertInertia(fn (Assert $page) => $page
        ->where('rateio', [
            'dividiu' => ['lancamentos' => 2, 'despesas' => 25000, 'receitas' => 0],
            'recebeu' => ['lancamentos' => 1, 'despesas' => 8000, 'receitas' => 0],
        ]));
});

it('CA-149.1 sends only the summary, the expenses by category and the forecast in the simple mode', function () {
    $this->user->forceFill(['modo_simples' => true, 'ultima_carteira_id' => $this->x->id])->save();
    exemploResumo();

    relatorioDeX(['periodo' => '3m'])->assertInertia(fn (Assert $page) => $page
        ->where('resumo.receitas', 1000000)
        ->has('despesasPorCategoria.ranking')
        ->has('previsao.meses', 6)
        ->missing('evolucao')
        ->missing('despesasPorTipo')
        ->missing('compromissos')
        ->missing('carteiras')
        ->missing('rateio'));
});

it('CA-149.2 ignores the wallet of the URL in the simple mode', function () {
    $y = Carteira::factory()->for($this->user)->create(['nome' => 'Y']);
    $this->user->forceFill(['modo_simples' => true, 'ultima_carteira_id' => $this->x->id])->save();

    $this->actingAs($this->user)->get(route('relatorio', ['carteira' => $y->id]))->assertInertia(fn (Assert $page) => $page
        ->where('carteira.id', $this->x->id));

    expect($this->user->fresh()->ultima_carteira_id)->toBe($this->x->id);
});
