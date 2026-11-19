<?php

namespace Database\Factories;

use App\Models\Fatura;
use App\Models\PagamentoFatura;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PagamentoFatura>
 */
class PagamentoFaturaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fatura_id' => Fatura::factory(),
            'carteira_id' => null,
            'valor' => fake()->numberBetween(100, 100_000),
            'data' => now()->toDateString(),
        ];
    }
}
