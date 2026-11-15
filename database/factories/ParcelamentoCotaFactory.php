<?php

namespace Database\Factories;

use App\Models\Parcelamento;
use App\Models\ParcelamentoCota;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ParcelamentoCota>
 */
class ParcelamentoCotaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parcelamento_id' => Parcelamento::factory(),
            'user_id' => User::factory(),
            'valor' => null,
            'percentual' => null,
        ];
    }
}
