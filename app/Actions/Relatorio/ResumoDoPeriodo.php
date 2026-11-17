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
     * the current one starts.
     *
     * @return array{receitas: int, despesas: int, resultado: int, taxaPoupanca: float|null, variacoes: array{receitas: float|null, despesas: float|null, resultado: float|null}}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $inicioAnterior, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $atual = 'lancamentos.data >= ?';
        $inicioDoPeriodo = $inicio->toDateString();

        $totais = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.data', '>=', $inicioAnterior->toDateString())
            ->where('lancamentos.data', '<', $fim->toDateString())
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
            'taxaPoupanca' => Porcentagem::de($resultado, $receitas),
            'variacoes' => [
                'receitas' => Porcentagem::variacao($receitas, $receitasAnteriores),
                'despesas' => Porcentagem::variacao($despesas, $despesasAnteriores),
                'resultado' => Porcentagem::variacao($resultado, $receitasAnteriores - $despesasAnteriores),
            ],
        ];
    }
}
