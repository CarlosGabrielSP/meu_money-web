<?php

namespace Database\Factories;

use App\Models\Carteira;
use App\Models\Fatura;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Fatura>
 */
class FaturaFactory extends Factory
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
            'referencia' => '2026-12-01',
            'fechamento' => '2026-11-30',
            'vencimento' => '2026-12-10',
            'carteira_vinculada_id' => null,
            'datas_editadas' => false,
        ];
    }
}
