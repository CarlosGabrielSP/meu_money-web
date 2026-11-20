<?php

namespace App\Actions\Inicio;

use App\Actions\Cartoes\SaldosVinculados;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;

class CalcularSaldos
{
    public function __construct(private SaldosVinculados $saldosVinculados) {}

    /**
     * Balances of the user's shares in one wallet for one month (RN-020 to RN-026, RN-092), plus what is
     * still to receive and to pay until the end of the month, from this month or earlier ones (RN-169).
     *
     * Estimated balances count executed and pending shares; current balances only executed ones.
     * Filters never apply here (RN-097). Dates are compared against the next month start because
     * the date cast stores "Y-m-d 00:00:00", which is greater than the bare last day string.
     *
     * A wallet that pays card invoices works on a cash basis (RN-196, RN-226, DEC-087): payments
     * leave the current balance on their date; income and purchases of the invoices due in the month
     * enter income and expenses; what the invoices still have to pay enters "a pagar"; the estimated
     * balance is the current one plus "a receber" minus "a pagar" (data model 05, R5). The month
     * result is income minus expenses, and the current one also loses the payments of the month.
     * The "a receber" items of the contacts' parts the user settled (RN-216, RN-245): their receipts
     * enter the current balance and what is still pending enters "a receber", so the estimated
     * balance gains their total; they stay out of the month income and result (RN-243, P-047).
     *
     * @param  array<int, array{pagamentosAte: int, pagamentosNoMes: int, aPagar: int, receitas: int, despesas: int, quitacoesAte: int, recebimentosAte: int}>|null  $vinculados  SaldosVinculados of the month, to reuse in the request
     * @return array{acumulado: array{estimado: int, atual: int}, mes: array{estimado: int, atual: int}, receitas: int, despesas: int, aReceber: int, aPagar: int}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $mes, ?array $vinculados = null): array
    {
        $inicio = $mes->startOfMonth()->toDateString();
        $proximoMes = $mes->startOfMonth()->addMonth()->toDateString();
        $comSinal = "case when lancamentos.tipo = 'receita' then cotas.valor else -cotas.valor end";

        $saldos = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.data', '<', $proximoMes)
            ->selectRaw("coalesce(sum(case when cotas.executado then {$comSinal} else 0 end), 0) as acumulado_atual")
            ->selectRaw("coalesce(sum(case when lancamentos.data >= ? and cotas.executado then {$comSinal} else 0 end), 0) as mes_atual", [$inicio])
            ->selectRaw("coalesce(sum(case when lancamentos.data >= ? and lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as receitas", [$inicio])
            ->selectRaw("coalesce(sum(case when lancamentos.data >= ? and lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as despesas", [$inicio])
            ->selectRaw("coalesce(sum(case when not cotas.executado and lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as a_receber")
            ->selectRaw("coalesce(sum(case when not cotas.executado and lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as a_pagar")
            ->toBase()
            ->first();

        $cartoes = ($vinculados ?? $this->saldosVinculados->handle($user, $mes))[$carteira->id]
            ?? ['pagamentosAte' => 0, 'pagamentosNoMes' => 0, 'aPagar' => 0, 'receitas' => 0, 'despesas' => 0, 'quitacoesAte' => 0, 'recebimentosAte' => 0];
        $atual = (int) $saldos?->acumulado_atual - $cartoes['pagamentosAte'] + $cartoes['recebimentosAte'];
        $receitas = (int) $saldos?->receitas + $cartoes['receitas'];
        $despesas = (int) $saldos?->despesas + $cartoes['despesas'];
        $aReceber = (int) $saldos?->a_receber + $cartoes['quitacoesAte'] - $cartoes['recebimentosAte'];
        $aPagar = (int) $saldos?->a_pagar + $cartoes['aPagar'];

        return [
            'acumulado' => ['estimado' => $atual + $aReceber - $aPagar, 'atual' => $atual],
            'mes' => ['estimado' => $receitas - $despesas, 'atual' => (int) $saldos?->mes_atual - $cartoes['pagamentosNoMes']],
            'receitas' => $receitas,
            'despesas' => $despesas,
            'aReceber' => $aReceber,
            'aPagar' => $aPagar,
        ];
    }
}
