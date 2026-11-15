<?php

namespace Database\Factories;

use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\Grupo;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Grupo>
 */
class GrupoFactory extends Factory
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
            'nome' => fake()->words(2, true),
            'tipo' => TipoLancamento::Despesa,
        ];
    }
}
