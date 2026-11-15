<?php

namespace Database\Factories;

use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Lancamento;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Cota>
 */
class CotaFactory extends Factory
{
    private ?Lancamento $lancamentoCarregado = null;

    /**
     * Define the model's default state.
     *
     * The share inherits author and value from its entry (DEC-017) and reuses
     * an existing wallet and category of that user when there is one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lancamento_id' => Lancamento::factory(),
            'user_id' => fn (array $attributes) => $this->lancamento($attributes)->user_id,
            'carteira_id' => fn (array $attributes) => Carteira::query()->where('user_id', $attributes['user_id'])->value('id')
                ?? Carteira::factory()->create(['user_id' => $attributes['user_id']]),
            'categoria_id' => fn (array $attributes) => Categoria::query()->where('user_id', $attributes['user_id'])->value('id')
                ?? Categoria::factory()->create(['user_id' => $attributes['user_id']]),
            'valor' => fn (array $attributes) => $this->lancamento($attributes)->valor,
            'executado' => false,
        ];
    }

    /**
     * Load the share's entry once per entry id.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function lancamento(array $attributes): Lancamento
    {
        $lancamentoId = $attributes['lancamento_id'];

        if ($this->lancamentoCarregado?->getKey() !== $lancamentoId) {
            $this->lancamentoCarregado = Lancamento::query()->whereKey($lancamentoId)->firstOrFail();
        }

        return $this->lancamentoCarregado;
    }

    /**
     * An executed share (RN-014).
     */
    public function executado(): static
    {
        return $this->state(fn (array $attributes) => [
            'executado' => true,
            'executado_em' => now(),
        ]);
    }
}
