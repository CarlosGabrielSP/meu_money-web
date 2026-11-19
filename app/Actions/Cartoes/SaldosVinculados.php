<?php

namespace App\Actions\Cartoes;

use App\Models\Fatura;
use App\Models\PagamentoFatura;
use App\Models\User;
use Carbon\CarbonImmutable;

class SaldosVinculados
{
    public function __construct(private CalcularFaturas $calcularFaturas) {}

    /**
     * What the user's cards change in each common wallet until the last day of the month (RN-196,
     * RN-226, RN-229, data model 05 R5), in two queries whatever the number of cards (RNF-030):
     *
     * - pagamentosAte / pagamentosNoMes: invoice payments that left the wallet until that day, and in
     *   the month;
     * - aPagar: for each card, the positive to pay on that day of the last invoice paid by the wallet
     *   with due date until that day (payments after that day do not count);
     * - receitas / despesas: income and purchases of the invoices paid by the wallet that are due in
     *   the month.
     *
     * @param  array<int, list<array{fatura: Fatura, compras: int, receitas: int, aPagarAte: int}>>|null  $faturas  CalcularFaturas computed until the last day of the month, to reuse in the request
     * @return array<int, array{pagamentosAte: int, pagamentosNoMes: int, aPagar: int, receitas: int, despesas: int}> by common wallet id
     */
    public function handle(User $user, CarbonImmutable $mes, ?array $faturas = null): array
    {
        $inicio = $mes->startOfMonth();
        $fim = $inicio->endOfMonth()->startOfDay();
        $proximoMes = $inicio->addMonthNoOverflow();
        $saldos = [];
        $vazio = ['pagamentosAte' => 0, 'pagamentosNoMes' => 0, 'aPagar' => 0, 'receitas' => 0, 'despesas' => 0];

        $pagamentos = PagamentoFatura::query()
            ->join('carteiras', 'carteiras.id', '=', 'pagamentos_fatura.carteira_id')
            ->where('carteiras.user_id', $user->id)
            ->where('pagamentos_fatura.data', '<', $proximoMes->toDateString())
            ->groupBy('pagamentos_fatura.carteira_id')
            ->selectRaw('pagamentos_fatura.carteira_id, sum(pagamentos_fatura.valor) as ate')
            ->selectRaw('coalesce(sum(case when pagamentos_fatura.data >= ? then pagamentos_fatura.valor else 0 end), 0) as no_mes', [$inicio->toDateString()])
            ->toBase()
            ->get();

        foreach ($pagamentos as $linha) {
            $saldos[(int) $linha->carteira_id] = [...$vazio, 'pagamentosAte' => (int) $linha->ate, 'pagamentosNoMes' => (int) $linha->no_mes];
        }

        foreach ($faturas ?? $this->calcularFaturas->handle($user, ate: $fim) as $doCartao) {
            $ultimaVencida = [];

            foreach ($doCartao as $linha) {
                $fatura = $linha['fatura'];
                $vinculada = $fatura->carteira_vinculada_id;

                if ($vinculada === null || $fatura->vencimento->greaterThan($fim)) {
                    continue;
                }

                $ultimaVencida[$vinculada] = $linha['aPagarAte'];

                if ($fatura->vencimento->greaterThanOrEqualTo($inicio)) {
                    $saldos[$vinculada] ??= $vazio;
                    $saldos[$vinculada]['receitas'] += $linha['receitas'];
                    $saldos[$vinculada]['despesas'] += $linha['compras'];
                }
            }

            foreach ($ultimaVencida as $vinculada => $aPagar) {
                $saldos[$vinculada] ??= $vazio;
                $saldos[$vinculada]['aPagar'] += max(0, $aPagar);
            }
        }

        return $saldos;
    }
}
