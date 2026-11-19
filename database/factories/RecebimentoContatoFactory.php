<?php

namespace Database\Factories;

use App\Models\QuitacaoContato;
use App\Models\RecebimentoContato;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecebimentoContato>
 */
class RecebimentoContatoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'quitacao_contato_id' => QuitacaoContato::factory(),
            'valor' => fake()->numberBetween(100, 100_000),
            'data' => now()->toDateString(),
            'pagamento_fatura_id' => null,
        ];
    }
}
