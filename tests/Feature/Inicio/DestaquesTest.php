<?php

use App\Enums\TipoCategoria;
use App\Models\Carteira;
use App\Models\Categoria;
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
 * One share of the user in the wallet with the given date, type, amount in cents and status.
 */
function cotaEm(Carteira $carteira, Categoria $categoria, string $data, string $tipo, int $valor, bool $executado = false, string $descricao = 'Item'): void
{
    lancamentoComCota(test()->user, $carteira, $categoria, ['data' => $data, 'tipo' => $tipo, 'valor' => $valor, 'descricao' => $descricao], ['executado' => $executado]);
}

/**
 * @param  array<string, mixed>  $parametros
 */
function inicioDeX(array $parametros = []): TestResponse
{
    return test()->actingAs(test()->user)->get(route('home', ['carteira' => test()->x->id, ...$parametros]));
}

it('CA-130.1 CA-130.2 CA-130.3 sends what is left to receive and to pay until the end of the month', function (string $mes, bool $vencidaDeOutubro, int $aReceber, int $aPagar) {
    exemploSaldos($this->user, $this->x, $this->categoria);

    if ($vencidaDeOutubro) {
        cotaEm($this->x, $this->categoria, '2026-10-20', 'despesa', 10000);
    }

    inicioDeX(['mes' => $mes])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.aReceber', $aReceber)
        ->where('saldos.aPagar', $aPagar));
})->with([
    'November' => ['2026-11', false, 0, 150000],
    'December' => ['2026-12', false, 500000, 150000],
    'November with an overdue October expense' => ['2026-11', true, 0, 160000],
]);

it('CA-131.1 sends the current accumulated balance of every wallet until the end of the month, 0 without shares', function () {
    exemploSaldos($this->user, $this->x, $this->categoria);
    $y = Carteira::factory()->for($this->user)->create(['nome' => 'Y']);
    cotaEm($y, $this->categoria, '2026-11-02', 'receita', 30000, executado: true);
    cotaEm($y, $this->categoria, '2026-12-03', 'receita', 5000, executado: true);

    inicioDeX(['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where("destaques.saldosCarteiras.{$this->x->id}", 700000)
        ->where("destaques.saldosCarteiras.{$y->id}", 30000)
        ->where("destaques.saldosCarteiras.{$this->principal->id}", 0));
});

it('CA-131.2 keeps the number of queries constant as wallets grow', function () {
    $consultas = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        inicioDeX(['mes' => '2026-11'])->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $this->user->forceFill(['ultima_carteira_id' => $this->x->id])->save();
    cotaEm($this->x, $this->categoria, '2026-11-02', 'receita', 100);
    $comUma = $consultas();

    foreach (range(1, 4) as $indice) {
        cotaEm(Carteira::factory()->for($this->user)->create(), $this->categoria, '2026-11-02', 'receita', 100, executado: true);
    }

    expect($consultas())->toBe($comUma);
});

/**
 * Overdue sample data of RF-132.
 */
function exemploVencidas(): void
{
    $x = test()->x;
    $categoria = test()->categoria;

    cotaEm($x, $categoria, '2026-10-20', 'despesa', 10000, descricao: 'Luz');
    cotaEm($x, $categoria, '2026-11-10', 'despesa', 5000, descricao: 'Farmácia');
    cotaEm($x, $categoria, '2026-11-12', 'receita', 3000, descricao: 'Freela');
    cotaEm($x, $categoria, '2026-11-14', 'despesa', 2000, descricao: 'Hoje');
    cotaEm($x, $categoria, '2026-11-05', 'despesa', 7000, executado: true);
    cotaEm(Carteira::factory()->for(test()->user)->create(), $categoria, '2026-11-01', 'despesa', 99900);
}

it('CA-132.1 CA-132.2 summarizes the overdue shares of the wallet regardless of month and filters', function (array $parametros) {
    exemploVencidas();

    inicioDeX($parametros)->assertInertia(fn (Assert $page) => $page
        ->where('destaques.vencidas', ['quantidade' => 3, 'receitas' => 3000, 'despesas' => 15000])
        ->missing('cotasVencidas'));
})->with([
    'current month' => [[]],
    'March 2027 filtered by income' => [['mes' => '2027-03', 'tipo' => 'receita']],
]);

it('CA-132.3 loads the overdue panel on request, most recent first', function () {
    exemploVencidas();

    inicioDeX()->assertInertia(fn (Assert $page) => $page
        ->reloadOnly('cotasVencidas', fn (Assert $reload) => $reload
            ->where('cotasVencidas', fn ($cotas) => collect($cotas)->map(fn ($cota) => [$cota['data'], $cota['descricao'], $cota['tipo'], $cota['valor']])->all() === [
                ['2026-11-12', 'Freela', 'receita', 3000],
                ['2026-11-10', 'Farmácia', 'despesa', 5000],
                ['2026-10-20', 'Luz', 'despesa', 10000],
            ])
            ->has('cotasVencidas.0.cotaId')));
});

it('CA-132.4 counts every overdue share but lists at most 50', function () {
    foreach (range(1, 60) as $dia) {
        cotaEm($this->x, $this->categoria, CarbonImmutable::parse('2026-09-01')->addDays($dia)->toDateString(), 'despesa', 100);
    }

    inicioDeX()->assertInertia(fn (Assert $page) => $page
        ->where('destaques.vencidas.quantidade', 60)
        ->reloadOnly('cotasVencidas', fn (Assert $reload) => $reload->has('cotasVencidas', 50)));
});

it('CA-132.5 drops a share marked as executed from the overdue summary', function () {
    exemploVencidas();
    $farmacia = $this->user->cotas()->whereRelation('lancamento', 'descricao', 'Farmácia')->sole();

    $this->actingAs($this->user)->patch(route('cotas.executado', $farmacia), ['executado' => true]);

    inicioDeX()->assertInertia(fn (Assert $page) => $page
        ->where('destaques.vencidas', ['quantidade' => 2, 'receitas' => 3000, 'despesas' => 10000]));
});

/**
 * Upcoming sample data of RF-133.
 */
function exemploProximas(): void
{
    $x = test()->x;
    $categoria = test()->categoria;

    cotaEm($x, $categoria, '2026-11-13', 'despesa', 1000);
    cotaEm($x, $categoria, '2026-11-20', 'despesa', 6000, descricao: 'Internet');
    cotaEm($x, $categoria, '2026-11-14', 'despesa', 4000, descricao: 'Gás');
    cotaEm($x, $categoria, '2026-11-21', 'despesa', 8000);
    cotaEm($x, $categoria, '2026-11-15', 'receita', 9000);
    cotaEm($x, $categoria, '2026-11-16', 'despesa', 7000, executado: true);
}

it('CA-133.1 CA-133.3 summarizes the expenses due in the next 7 days regardless of month and filters', function (array $parametros) {
    exemploProximas();

    inicioDeX($parametros)->assertInertia(fn (Assert $page) => $page
        ->where('destaques.proximas', ['quantidade' => 2, 'total' => 10000]));
})->with([
    'current month' => [[]],
    'March 2027 filtered' => [['mes' => '2027-03', 'status' => 'executado']],
]);

it('CA-133.2 loads the upcoming panel on request, soonest first', function () {
    exemploProximas();

    inicioDeX()->assertInertia(fn (Assert $page) => $page
        ->missing('cotasProximas')
        ->reloadOnly('cotasProximas', fn (Assert $reload) => $reload
            ->where('cotasProximas', fn ($cotas) => collect($cotas)->pluck('descricao')->all() === ['Gás', 'Internet'])));
});

/**
 * Month spending sample data of RF-134 with the system categories.
 */
function exemploGastos(): void
{
    test()->seed(CategoriasGlobaisSeeder::class);
    $x = test()->x;
    $categoria = fn (string $nome): Categoria => Categoria::query()->globais()->where('nome', $nome)->sole();

    cotaEm($x, $categoria('Mercado'), '2026-11-03', 'despesa', 30000, executado: true);
    cotaEm($x, $categoria('Mercado'), '2026-11-20', 'despesa', 10000);
    cotaEm($x, $categoria('Moradia'), '2026-11-10', 'despesa', 150000);
    cotaEm($x, $categoria('Lazer'), '2026-11-08', 'despesa', 20000);
    cotaEm($x, $categoria('Saúde'), '2026-11-09', 'despesa', 5000);
    cotaEm($x, $categoria('Salário'), '2026-11-05', 'receita', 500000);
    cotaEm($x, $categoria('Lazer'), '2026-10-08', 'despesa', 90000);
}

it('CA-134.1 CA-134.2 sends the three categories with the largest expenses of the month regardless of filters', function (bool $filtrarSaude) {
    exemploGastos();
    $parametros = $filtrarSaude ? ['categoria' => Categoria::query()->globais()->where('nome', 'Saúde')->value('id')] : [];

    inicioDeX(['mes' => '2026-11', ...$parametros])->assertInertia(fn (Assert $page) => $page
        ->where('saldos.despesas', 215000)
        ->where('destaques.gastosDoMes', [
            ['nome' => 'Moradia', 'icone' => 'house', 'valor' => 150000],
            ['nome' => 'Mercado', 'icone' => 'shopping-cart', 'valor' => 40000],
            ['nome' => 'Lazer', 'icone' => 'gamepad-2', 'valor' => 20000],
        ]));
})->with([
    'no filter' => [false],
    'category filter' => [true],
]);

it('CA-134.3 sends no spending for a month without expenses', function () {
    exemploGastos();

    inicioDeX(['mes' => '2027-02'])->assertInertia(fn (Assert $page) => $page->where('destaques.gastosDoMes', []));
});

it('CA-134.4 breaks a tie in third place by category name', function () {
    exemploGastos();
    cotaEm($this->x, Categoria::query()->globais()->where('nome', 'Contas')->sole(), '2026-11-11', 'despesa', 20000);

    inicioDeX(['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('destaques.gastosDoMes.2.nome', 'Contas'));
});

it('CA-136.1 sends no highlights in the simple mode and keeps its balances', function () {
    $this->user->forceFill(['modo_simples' => true])->save();
    exemploSaldos($this->user, $this->x, $this->categoria);
    cotaEm($this->x, $this->categoria, '2026-11-10', 'despesa', 5000);

    inicioDeX(['mes' => '2026-11'])->assertInertia(fn (Assert $page) => $page
        ->where('destaques', null)
        ->where('saldos.acumulado.atual', 700000)
        ->where('saldos.receitas', 500000));
});
