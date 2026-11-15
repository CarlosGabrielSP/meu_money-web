<?php

namespace Database\Factories;

use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Models\Carteira;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Carteira>
 */
class CarteiraFactory extends Factory
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
            'nome' => fake()->words(2, true),
            'padrao' => false,
            'cor' => CorCarteira::Blue,
            'icone' => IconeCarteira::Wallet,
        ];
    }

    /**
     * Mark the wallet as the user's default wallet (RN-003).
     */
    public function padrao(): static
    {
        return $this->state(fn (array $attributes) => [
            'padrao' => true,
        ]);
    }
}
