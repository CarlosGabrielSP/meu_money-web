<?php

namespace App\Actions\Relatorio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;

class ResumoDoPeriodo
{
    /**
     * Estimated income, expenses, result and savings rate of the user's shares in the wallet, with the
     * change against the previous period, in one query (RN-178, RN-180). The previous period ends where
     * the current one starts. Card purchases count on the due date of their invoice, in the card and in
     * the wallet that pays it; invoice payments are not expenses (RN-199, RN-209, RN-230). A card has
     * no savings rate (RN-227, R7).
     *
     * @return array{receitas: int, despesas: int, resultado: int, taxaPoupanca: float|null, variacoes: array{receitas: float|null, despesas: float|null, resultado: float|null}}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $inicioAnterior, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $atual = Cota::DATA_EFETIVA.' >= ?';
        $inicioDoPeriodo = $inicio->toDateString();

        $totais = Cota::query()
            ->naCarteiraPorVencimento($carteira)
            ->where('cotas.user_id', $user->id)
            ->whereRaw(Cota::DATA_EFETIVA.' >= ?', [$inicioAnterior->toDateString()])
            ->whereRaw(Cota::DATA_EFETIVA.' < ?', [$fim->toDateString()])
            ->selectRaw("coalesce(sum(case when {$atual} and lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as receitas", [$inicioDoPeriodo])
            ->selectRaw("coalesce(sum(case when {$atual} and lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as despesas", [$inicioDoPeriodo])
            ->selectRaw("coalesce(sum(case when not ({$atual}) and lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as receitas_anteriores", [$inicioDoPeriodo])
            ->selectRaw("coalesce(sum(case when not ({$atual}) and lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as despesas_anteriores", [$inicioDoPeriodo])
            ->toBase()
            ->first();

        $receitas = (int) $totais?->receitas;
        $despesas = (int) $totais?->despesas;
        $receitasAnteriores = (int) $totais?->receitas_anteriores;
        $despesasAnteriores = (int) $totais?->despesas_anteriores;
        $resultado = $receitas - $despesas;

        return [
            'receitas' => $receitas,
            'despesas' => $despesas,
            'resultado' => $resultado,
            'taxaPoupanca' => $carteira->isCartao() ? null : Porcentagem::de($resultado, $receitas),
            'variacoes' => [
                'receitas' => Porcentagem::variacao($receitas, $receitasAnteriores),
                'despesas' => Porcentagem::variacao($despesas, $despesasAnteriores),
                'resultado' => Porcentagem::variacao($resultado, $receitasAnteriores - $despesasAnteriores),
            ],
        ];
    }
}
