<?php

use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Contato;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategoriasGlobaisSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-14 10:00'));
    $this->seed(CategoriasGlobaisSeeder::class);
    $this->a = User::factory()->create(['name' => 'Ana']);
    $this->b = User::factory()->create(['name' => 'Bruno']);
    $this->x = Carteira::factory()->for($this->a)->padrao()->create(['nome' => 'X']);
    Carteira::factory()->for($this->b)->padrao()->create();
    Contato::factory()->aceito()->create(['solicitante_id' => $this->a->id, 'convidado_id' => $this->b->id]);
    $this->mercado = Categoria::query()->globais()->where('nome', 'Mercado')->sole();
});

/**
 * One entry of each kind the main screen shows in wallet X for 2026-11: simple, installment,
 * recurring, split by the author, received from a contact, authored without a share and grouped.
 */
function umDeCadaTipo(): void
{
    $a = test()->a;
    $b = test()->b;
    $x = test()->x;
    $mercado = test()->mercado;

    $simples = lancamentoComCota($a, $x, $mercado, ['tipo' => 'despesa', 'data' => '2026-11-10'])->cotas()->sole();

    test()->actingAs($a)->post(route('lancamentos.store'), dadosSerie($x, $mercado, ['data' => '2026-11-05']))->assertSessionHasNoErrors();
    test()->actingAs($a)->post(route('lancamentos.store'), dadosSerie($x, $mercado, ['data' => '2026-11-06', 'repeticao' => 'recorrente', 'total_parcelas' => null, 'modo_valor' => null]))->assertSessionHasNoErrors();
    test()->actingAs($a)->post(route('lancamentos.store'), dadosRateio($x, $mercado, 'iguais', [['user_id' => $a->id], ['user_id' => $b->id]]))->assertSessionHasNoErrors();
    test()->actingAs($a)->post(route('lancamentos.store'), dadosRateio($x, $mercado, 'iguais', [['user_id' => $b->id]]))->assertSessionHasNoErrors();
    test()->actingAs($b)->post(route('lancamentos.store'), dadosRateio($b->carteiras()->sole(), $mercado, 'iguais', [['user_id' => $b->id], ['user_id' => $a->id]]))->assertSessionHasNoErrors();

    $outra = lancamentoComCota($a, $x, $mercado, ['tipo' => 'despesa', 'data' => '2026-11-12'])->cotas()->sole();
    test()->actingAs($a)->post(route('grupos.agrupar'), ['cotas' => [$simples->id, $outra->id], 'nome' => 'Fatura'])->assertSessionHasNoErrors();
}

/**
 * Number of queries of one main screen request of Ana.
 *
 * @param  array<string, mixed>  $parametros
 */
function consultasDaTelaPrincipal(array $parametros = []): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs(test()->a)->get(route('home', ['mes' => '2026-11', ...$parametros]))->assertOk();

    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

it('RNF-030 keeps the number of main screen queries constant as entries grow', function (array $filtros) {
    umDeCadaTipo();
    $comPoucos = consultasDaTelaPrincipal($filtros);

    umDeCadaTipo();
    umDeCadaTipo();
    $comMais = consultasDaTelaPrincipal($filtros);

    expect(Cota::query()->where('user_id', $this->a->id)->count())->toBeGreaterThan(20)
        ->and($comMais)->toBe($comPoucos);
})->with([
    'no filter' => [[]],
    'status filter' => [['status' => 'pendente']],
]);
