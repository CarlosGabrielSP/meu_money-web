<?php

namespace Database\Factories;

use App\Enums\OrigemQuitacao;
use App\Models\Fatura;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<QuitacaoContato>
 */
class QuitacaoContatoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'fatura_id' => Fatura::factory(),
            'contato_id' => User::factory(),
            'valor' => fake()->numberBetween(100, 100_000),
            'origem' => OrigemQuitacao::Contato,
            'pagamento_fatura_id' => PagamentoFatura::factory(),
        ];
    }
}
