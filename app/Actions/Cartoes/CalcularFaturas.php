<?php

namespace App\Actions\Cartoes;

use App\Models\Cota;
use App\Models\Fatura;
use App\Models\PagamentoFatura;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CalcularFaturas
{
    /**
     * Amounts of the stored invoices of the user's cards, in one query whatever their number (RN-195,
     * RN-198, RN-230, RNF-030). In Fase 14 every share in a card belongs to the card owner (RN-231).
     *
     * To pay = previous balance + purchases − income − payments; the previous balance is what the
     * invoice before left to pay, negative when it left a credit. Invoices never created have no
     * purchases nor payments, so the next stored one carries the balance. "aPagarAte" counts only the
     * payments dated until $ate, for the estimated balance on a past day (RN-196, R5).
     *
     * @param  list<int>|null  $cartoes  card wallet ids, or every card of the user
     * @return array<int, list<array{fatura: Fatura, compras: int, receitas: int, pagamentos: int, saldoAnterior: int, aPagar: int, aPagarAte: int}>> by card wallet id, by reference month
     */
    public function handle(User $user, ?array $cartoes = null, ?CarbonImmutable $ate = null): array
    {
        $ateExclusivo = ($ate ?? CarbonImmutable::today())->startOfDay()->addDay()->toDateString();
        $somaDasCotas = fn (string $tipo): Builder => Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->whereColumn('cotas.fatura_id', 'faturas.id')
            ->where('lancamentos.tipo', $tipo)
            ->selectRaw('coalesce(sum(cotas.valor), 0)');

        $linhas = Fatura::query()
            ->join('carteiras', 'carteiras.id', '=', 'faturas.carteira_id')
            ->where('carteiras.user_id', $user->id)
            ->when($cartoes !== null, fn (Builder $query) => $query->whereIn('faturas.carteira_id', $cartoes))
            ->select('faturas.*')
            ->selectSub($somaDasCotas('despesa'), 'soma_compras')
            ->selectSub($somaDasCotas('receita'), 'soma_receitas')
            ->selectSub(PagamentoFatura::query()->whereColumn('fatura_id', 'faturas.id')->selectRaw('coalesce(sum(valor), 0)'), 'soma_pagamentos')
            ->selectSub(PagamentoFatura::query()->whereColumn('fatura_id', 'faturas.id')->where('data', '<', $ateExclusivo)->selectRaw('coalesce(sum(valor), 0)'), 'soma_pagamentos_ate')
            ->orderBy('faturas.carteira_id')
            ->orderBy('faturas.referencia')
            ->get();

        $porCartao = [];
        $acumulados = [];

        foreach ($linhas as $fatura) {
            $cartao = $fatura->carteira_id;
            [$acumulado, $acumuladoAte] = $acumulados[$cartao] ?? [0, 0];
            $compras = (int) $fatura->getAttribute('soma_compras');
            $receitas = (int) $fatura->getAttribute('soma_receitas');
            $pagamentos = (int) $fatura->getAttribute('soma_pagamentos');
            $saldoAnterior = $acumulado;

            $acumulado += $compras - $receitas - $pagamentos;
            $acumuladoAte += $compras - $receitas - (int) $fatura->getAttribute('soma_pagamentos_ate');
            $acumulados[$cartao] = [$acumulado, $acumuladoAte];

            $porCartao[$cartao][] = [
                'fatura' => $fatura,
                'compras' => $compras,
                'receitas' => $receitas,
                'pagamentos' => $pagamentos,
                'saldoAnterior' => $saldoAnterior,
                'aPagar' => $acumulado,
                'aPagarAte' => $acumuladoAte,
            ];
        }

        return $porCartao;
    }

    /**
     * To pay of the invoice due in the month: the stored one, else the balance left by the last one
     * before it (RN-195).
     *
     * @param  list<array{fatura: Fatura, aPagar: int}>  $faturas  of one card, by reference month
     */
    public static function aPagarNoMes(array $faturas, CarbonImmutable $referencia): int
    {
        $aPagar = 0;

        foreach ($faturas as $linha) {
            if ($linha['fatura']->referencia->greaterThan($referencia->startOfMonth())) {
                break;
            }

            $aPagar = $linha['aPagar'];
        }

        return $aPagar;
    }

    /**
     * Invoices whose to pay is zero or less: the user's part in them is settled (RN-214, RN-219).
     *
     * @param  array<int, list<array{fatura: Fatura, aPagar: int}>>  $porCartao
     * @return list<int> invoice ids
     */
    public static function quitadas(array $porCartao): array
    {
        $ids = [];

        foreach ($porCartao as $faturas) {
            foreach ($faturas as $linha) {
                if ($linha['aPagar'] <= 0) {
                    $ids[] = $linha['fatura']->id;
                }
            }
        }

        return $ids;
    }
}
