<?php

use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * A simple entry of the user with its single share in the given wallet and category (DEC-017).
 *
 * @param  array<string, mixed>  $lancamento
 * @param  array<string, mixed>  $cota
 */
function lancamentoComCota(User $user, Carteira $carteira, Categoria $categoria, array $lancamento = [], array $cota = []): Lancamento
{
    $criado = Lancamento::factory()->for($user)->create($lancamento);
    Cota::factory()->for($criado)->create([
        'carteira_id' => $carteira->id,
        'categoria_id' => $categoria->id,
        'executado_em' => ($cota['executado'] ?? false) ? now() : null,
        ...$cota,
    ]);

    return $criado;
}

/**
 * Form data of a new series (RF-050, RF-051).
 *
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosSerie(Carteira $carteira, Categoria $categoria, array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'TV',
        'valor' => '1.000,00',
        'data' => '2026-12-05',
        'categoria_id' => $categoria->id,
        'carteira_id' => $carteira->id,
        'repeticao' => 'parcelado',
        'periodicidade' => 'mensal',
        'total_parcelas' => 10,
        'modo_valor' => 'total',
        ...$dados,
    ];
}

/**
 * @return list<array{numero: int, data: string, valor: int, executado: bool}>
 */
function parcelasDaSerie(Parcelamento $serie): array
{
    return $serie->lancamentos()->with('cotas')->orderBy('numero_parcela')->get()
        ->map(fn (Lancamento $parcela) => [
            'numero' => $parcela->numero_parcela,
            'data' => $parcela->data->toDateString(),
            'valor' => $parcela->valor,
            'executado' => $parcela->cotas->sole()->executado,
        ])->all();
}

/**
 * @param  list<array<string, mixed>>  $participantes
 * @param  array<string, mixed>  $dados
 * @return array<string, mixed>
 */
function dadosRateio(Carteira $carteira, Categoria $categoria, string $modo, array $participantes, array $dados = []): array
{
    return [
        'tipo' => 'despesa',
        'descricao' => 'Jantar',
        'valor' => '100,00',
        'data' => '2026-11-20',
        'categoria_id' => $categoria->id,
        'carteira_id' => $carteira->id,
        'rateio' => ['modo' => $modo, 'participantes' => $participantes],
        ...$dados,
    ];
}

/**
 * @return array<int, int> share amount by user id
 */
function valoresDasCotas(Lancamento $lancamento): array
{
    return $lancamento->cotas()->orderBy('id')->pluck('valor', 'user_id')->all();
}
