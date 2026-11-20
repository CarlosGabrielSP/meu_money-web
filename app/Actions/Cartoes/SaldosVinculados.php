<?php

namespace App\Actions\Cartoes;

use App\Enums\OrigemQuitacao;
use App\Models\Fatura;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\RecebimentoContato;
use App\Models\User;
use Carbon\CarbonImmutable;

class SaldosVinculados
{
    public function __construct(private CalcularFaturas $calcularFaturas) {}

    /**
     * What the user's cards change in each common wallet until the last day of the month (RN-196,
     * RN-226, RN-229, data model 05 R5), in four queries whatever the number of cards (RNF-030):
     *
     * - pagamentosAte / pagamentosNoMes: invoice payments that left the wallet until that day, and in
     *   the month;
     * - aPagar: for each card, the positive to pay on that day of the last invoice paid by the wallet
     *   with due date until that day (payments after that day do not count), without the contacts'
     *   open parts, which never weigh on the author's wallet (RN-245);
     * - receitas / despesas: income and purchases of the invoices paid by the wallet that are due in
     *   the month, the author's own part only (RN-216);
     * - quitacoesAte / recebimentosAte: the "a receber" items of the wallet (settlements of origin
     *   `autor` paid from it, on the payment date) and their receipts, on the receipt date, until that
     *   day (RN-216, RN-242, RN-245, RN-261 e).
     *
     * @param  array<int, list<array{fatura: Fatura, compras: int, receitas: int, aPagarAutorAte: int}>>|null  $faturas  CalcularFaturas computed until the last day of the month, to reuse in the request
     * @return array<int, array{pagamentosAte: int, pagamentosNoMes: int, aPagar: int, receitas: int, despesas: int, quitacoesAte: int, recebimentosAte: int}> by common wallet id
     */
    public function handle(User $user, CarbonImmutable $mes, ?array $faturas = null): array
    {
        $inicio = $mes->startOfMonth();
        $fim = $inicio->endOfMonth()->startOfDay();
        $proximoMes = $inicio->addMonthNoOverflow();
        $saldos = [];
        $vazio = ['pagamentosAte' => 0, 'pagamentosNoMes' => 0, 'aPagar' => 0, 'receitas' => 0, 'despesas' => 0, 'quitacoesAte' => 0, 'recebimentosAte' => 0];

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

        $quitacoes = QuitacaoContato::query()
            ->join('pagamentos_fatura', 'pagamentos_fatura.id', '=', 'quitacoes_contato.pagamento_fatura_id')
            ->join('carteiras', 'carteiras.id', '=', 'pagamentos_fatura.carteira_id')
            ->where('carteiras.user_id', $user->id)
            ->where('quitacoes_contato.origem', OrigemQuitacao::Autor->value)
            ->where('pagamentos_fatura.data', '<', $proximoMes->toDateString())
            ->groupBy('pagamentos_fatura.carteira_id')
            ->selectRaw('pagamentos_fatura.carteira_id, sum(quitacoes_contato.valor) as total')
            ->toBase()
            ->get();

        foreach ($quitacoes as $linha) {
            $saldos[(int) $linha->carteira_id] ??= $vazio;
            $saldos[(int) $linha->carteira_id]['quitacoesAte'] = (int) $linha->total;
        }

        $recebimentos = RecebimentoContato::query()
            ->join('quitacoes_contato', 'quitacoes_contato.id', '=', 'recebimentos_contato.quitacao_contato_id')
            ->join('pagamentos_fatura', 'pagamentos_fatura.id', '=', 'quitacoes_contato.pagamento_fatura_id')
            ->join('carteiras', 'carteiras.id', '=', 'pagamentos_fatura.carteira_id')
            ->where('carteiras.user_id', $user->id)
            ->where('recebimentos_contato.data', '<', $proximoMes->toDateString())
            ->groupBy('pagamentos_fatura.carteira_id')
            ->selectRaw('pagamentos_fatura.carteira_id, sum(recebimentos_contato.valor) as total')
            ->toBase()
            ->get();

        foreach ($recebimentos as $linha) {
            $saldos[(int) $linha->carteira_id] ??= $vazio;
            $saldos[(int) $linha->carteira_id]['recebimentosAte'] = (int) $linha->total;
        }

        foreach ($faturas ?? $this->calcularFaturas->handle($user, ate: $fim) as $doCartao) {
            $ultimaVencida = [];

            foreach ($doCartao as $linha) {
                $fatura = $linha['fatura'];
                $vinculada = $fatura->carteira_vinculada_id;

                if ($vinculada === null || $fatura->vencimento->greaterThan($fim)) {
                    continue;
                }

                $ultimaVencida[$vinculada] = $linha['aPagarAutorAte'];

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
