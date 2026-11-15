<?php

namespace Database\Factories;

use App\Enums\Periodicidade;
use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Parcelamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Parcelamento>
 */
class ParcelamentoFactory extends Factory
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
            'carteira_id' => fn (array $attributes) => Carteira::factory()->create(['user_id' => $attributes['user_id']]),
            'categoria_id' => fn (array $attributes) => Categoria::factory()->create(['user_id' => $attributes['user_id']]),
            'descricao' => fake()->words(3, true),
            'tipo' => TipoLancamento::Despesa,
            'valor_parcela' => fake()->numberBetween(1_000, 100_000),
            'total_parcelas' => 10,
            'periodicidade' => Periodicidade::Mensal,
            'data_inicio' => '2026-11-10',
            'dia_vencimento' => 10,
            'gerado_ate' => '2027-08-10',
        ];
    }

    /**
     * A series without a fixed number of installments (RN-040).
     */
    public function recorrente(): static
    {
        return $this->state(fn (array $attributes) => [
            'total_parcelas' => null,
        ]);
    }
}
