<?php

use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Lancamento;
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
