<?php

namespace App\Actions\Relatorio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class RateioDoPeriodo
{
    /**
     * Sharing in the period (RN-187). "Você dividiu": shares of other participants in entries authored
     * by the user in the wallet. "Você recebeu": the user's shares in the wallet in entries of other
     * authors. Each with the number of entries and the sums by type; one query each.
     *
     * @return array{dividiu: array{lancamentos: int, despesas: int, receitas: int}, recebeu: array{lancamentos: int, despesas: int, receitas: int}}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $dividiu = $this->cotasDoPeriodo($inicio, $fim)
            ->where('lancamentos.user_id', $user->id)
            ->where('lancamentos.carteira_id', $carteira->id)
            ->where('cotas.user_id', '!=', $user->id);

        $recebeu = $this->cotasDoPeriodo($inicio, $fim)
            ->where('lancamentos.user_id', '!=', $user->id)
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id);

        return ['dividiu' => $this->totais($dividiu), 'recebeu' => $this->totais($recebeu)];
    }

    /**
     * @return Builder<Cota>
     */
    private function cotasDoPeriodo(CarbonImmutable $inicio, CarbonImmutable $fim): Builder
    {
        return Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('lancamentos.data', '>=', $inicio->toDateString())
            ->where('lancamentos.data', '<', $fim->toDateString());
    }

    /**
     * @param  Builder<Cota>  $cotas
     * @return array{lancamentos: int, despesas: int, receitas: int}
     */
    private function totais(Builder $cotas): array
    {
        $totais = $cotas
            ->selectRaw('count(distinct lancamentos.id) as lancamentos')
            ->selectRaw("coalesce(sum(case when lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as despesas")
            ->selectRaw("coalesce(sum(case when lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as receitas")
            ->toBase()
            ->first();

        return [
            'lancamentos' => (int) $totais?->lancamentos,
            'despesas' => (int) $totais?->despesas,
            'receitas' => (int) $totais?->receitas,
        ];
    }
}
