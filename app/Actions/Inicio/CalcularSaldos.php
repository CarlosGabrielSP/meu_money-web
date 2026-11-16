<?php

namespace App\Actions\Inicio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;

class CalcularSaldos
{
    /**
     * Balances of the user's shares in one wallet for one month (RN-020 to RN-026, RN-092), plus what is
     * still to receive and to pay until the end of the month, from this month or earlier ones (RN-169).
     *
     * Estimated balances count executed and pending shares; current balances only executed ones.
     * Filters never apply here (RN-097). Dates are compared against the next month start because
     * the date cast stores "Y-m-d 00:00:00", which is greater than the bare last day string.
     *
     * @return array{acumulado: array{estimado: int, atual: int}, mes: array{estimado: int, atual: int}, receitas: int, despesas: int, aReceber: int, aPagar: int}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $mes): array
    {
        $inicio = $mes->startOfMonth()->toDateString();
        $proximoMes = $mes->startOfMonth()->addMonth()->toDateString();
        $comSinal = "case when lancamentos.tipo = 'receita' then cotas.valor else -cotas.valor end";

        $saldos = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.data', '<', $proximoMes)
            ->selectRaw("coalesce(sum({$comSinal}), 0) as acumulado_estimado")
            ->selectRaw("coalesce(sum(case when cotas.executado then {$comSinal} else 0 end), 0) as acumulado_atual")
            ->selectRaw("coalesce(sum(case when lancamentos.data >= ? then {$comSinal} else 0 end), 0) as mes_estimado", [$inicio])
            ->selectRaw("coalesce(sum(case when lancamentos.data >= ? and cotas.executado then {$comSinal} else 0 end), 0) as mes_atual", [$inicio])
            ->selectRaw("coalesce(sum(case when lancamentos.data >= ? and lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as receitas", [$inicio])
            ->selectRaw("coalesce(sum(case when lancamentos.data >= ? and lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as despesas", [$inicio])
            ->selectRaw("coalesce(sum(case when not cotas.executado and lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as a_receber")
            ->selectRaw("coalesce(sum(case when not cotas.executado and lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as a_pagar")
            ->toBase()
            ->first();

        return [
            'acumulado' => ['estimado' => (int) $saldos?->acumulado_estimado, 'atual' => (int) $saldos?->acumulado_atual],
            'mes' => ['estimado' => (int) $saldos?->mes_estimado, 'atual' => (int) $saldos?->mes_atual],
            'receitas' => (int) $saldos?->receitas,
            'despesas' => (int) $saldos?->despesas,
            'aReceber' => (int) $saldos?->a_receber,
            'aPagar' => (int) $saldos?->a_pagar,
        ];
    }
}
