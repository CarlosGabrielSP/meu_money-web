<?php

namespace Database\Factories;

use App\Enums\StatusMembro;
use App\Models\Carteira;
use App\Models\CarteiraMembro;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CarteiraMembro>
 */
class CarteiraMembroFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'carteira_id' => Carteira::factory(),
            'user_id' => User::factory(),
            'status' => StatusMembro::Pendente,
            'respondido_em' => null,
        ];
    }

    /**
     * An accepted member (RN-270).
     */
    public function aceito(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusMembro::Aceito,
            'respondido_em' => now(),
        ]);
    }

    /**
     * A refused invitation (RN-270).
     */
    public function recusado(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusMembro::Recusado,
            'respondido_em' => now(),
        ]);
    }
}
