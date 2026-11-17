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
        return array_map(fn (array $saldos): int => $saldos['atual'], $this->atuaisEEstimados($user, $mes));
    }

    /**
     * Current and estimated accumulated balance of every wallet of the user until the last day of
     * the month, in one grouped query; a wallet without shares gets 0 (RN-021, RN-022, RN-186).
     *
     * @return array<int, array{atual: int, estimado: int}> balances in cents by wallet id
     */
    public function atuaisEEstimados(User $user, CarbonImmutable $mes): array
    {
        $comSinal = "case when lancamentos.id is null then 0 when lancamentos.tipo = 'receita' then cotas.valor else -cotas.valor end";

        return Carteira::query()
            ->where('carteiras.user_id', $user->id)
            ->leftJoin('cotas', fn (JoinClause $join) => $join
                ->on('cotas.carteira_id', '=', 'carteiras.id')
                ->where('cotas.user_id', $user->id))
            ->leftJoin('lancamentos', fn (JoinClause $join) => $join
                ->on('lancamentos.id', '=', 'cotas.lancamento_id')
                ->where('lancamentos.data', '<', $mes->startOfMonth()->addMonth()->toDateString()))
            ->groupBy('carteiras.id')
            ->select('carteiras.id')
            ->selectRaw("coalesce(sum(case when cotas.executado then {$comSinal} else 0 end), 0) as atual")
            ->selectRaw("coalesce(sum({$comSinal}), 0) as estimado")
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $carteira): array => [
                (int) $carteira->id => ['atual' => (int) $carteira->atual, 'estimado' => (int) $carteira->estimado],
            ])
            ->all();
    }
}
