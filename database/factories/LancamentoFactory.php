<?php

namespace Database\Factories;

use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lancamento>
 */
class LancamentoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'descricao' => fake()->words(3, true),
            'tipo' => TipoLancamento::Despesa,
            'valor' => fake()->numberBetween(100, 100_000),
            'data' => fake()->dateTimeBetween('-1 month', '+1 month')->format('Y-m-d'),
        ];
    }
}
