<?php

namespace Database\Factories;

use App\Enums\CompetenciaFatura;
use App\Enums\IconeCarteira;
use App\Enums\TipoCarteira;
use App\Models\Cartao;
use App\Models\Carteira;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cartao>
 */
class CartaoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'carteira_id' => Carteira::factory()->state(['tipo' => TipoCarteira::Cartao, 'icone' => IconeCarteira::CreditCard]),
            'dia_vencimento' => 10,
            'dias_fechamento' => Cartao::DIAS_FECHAMENTO_PADRAO,
            'competencia' => CompetenciaFatura::Fechamento,
            'carteira_vinculada_id' => null,
        ];
    }
}
