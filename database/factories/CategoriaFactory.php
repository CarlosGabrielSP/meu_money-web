<?php

namespace Database\Factories;

use App\Enums\IconeCategoria;
use App\Enums\TipoCategoria;
use App\Models\Categoria;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Categoria>
 */
class CategoriaFactory extends Factory
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
            'nome' => fake()->unique()->words(2, true),
            'tipo' => TipoCategoria::Despesa,
            'icone' => IconeCategoria::Tag,
        ];
    }

    /**
     * A system category without owner (RN-030).
     */
    public function global(): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => null,
        ]);
    }
}
