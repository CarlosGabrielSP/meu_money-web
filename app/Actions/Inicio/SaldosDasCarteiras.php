<?php

namespace App\Actions\Inicio;

use App\Models\Carteira;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

class SaldosDasCarteiras
{
    /**
     * Current accumulated balance of every wallet of the user until the last day of the month, in one
     * query; a wallet without executed shares gets 0 (RN-022, RN-023, RN-171, RNF-030).
     *
     * @return array<int, int> balance in cents by wallet id
     */
    public function handle(User $user, CarbonImmutable $mes): array
    {
        return Carteira::query()
            ->where('carteiras.user_id', $user->id)
            ->leftJoin('cotas', fn (JoinClause $join) => $join
                ->on('cotas.carteira_id', '=', 'carteiras.id')
                ->where('cotas.user_id', $user->id)
                ->where('cotas.executado', true))
            ->leftJoin('lancamentos', fn (JoinClause $join) => $join
                ->on('lancamentos.id', '=', 'cotas.lancamento_id')
                ->where('lancamentos.data', '<', $mes->startOfMonth()->addMonth()->toDateString()))
            ->groupBy('carteiras.id')
            ->selectRaw("carteiras.id, coalesce(sum(case when lancamentos.id is null then 0 when lancamentos.tipo = 'receita' then cotas.valor else -cotas.valor end), 0) as saldo")
            ->toBase()
            ->pluck('saldo', 'id')
            ->map(fn (mixed $saldo): int => (int) $saldo)
            ->all();
    }
}
