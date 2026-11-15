<?php

namespace Database\Factories;

use App\Enums\StatusContato;
use App\Models\Contato;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Contato>
 */
class ContatoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'solicitante_id' => User::factory(),
            'convidado_id' => User::factory(),
            'status' => StatusContato::Pendente,
            'respondido_em' => null,
        ];
    }

    /**
     * An accepted contact (RN-061).
     */
    public function aceito(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusContato::Aceito,
            'respondido_em' => now(),
        ]);
    }
}
