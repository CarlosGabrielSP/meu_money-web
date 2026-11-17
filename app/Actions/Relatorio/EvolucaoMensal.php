<?php

namespace App\Actions\Relatorio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;

class EvolucaoMensal
{
    /**
     * Column aliases of each month, at most 12 (RN-179, RN-184); literal so the SQL stays literal.
     */
    private const array RECEITAS = ['receitas_0', 'receitas_1', 'receitas_2', 'receitas_3', 'receitas_4', 'receitas_5', 'receitas_6', 'receitas_7', 'receitas_8', 'receitas_9', 'receitas_10', 'receitas_11'];

    private const array DESPESAS = ['despesas_0', 'despesas_1', 'despesas_2', 'despesas_3', 'despesas_4', 'despesas_5', 'despesas_6', 'despesas_7', 'despesas_8', 'despesas_9', 'despesas_10', 'despesas_11'];

    private const string NO_MES = 'lancamentos.data >= ? and lancamentos.data < ?';

    /**
     * Estimated income and expenses of each month and the estimated accumulated balance on its last
     * day, for the user's shares in the wallet (RN-178, RN-181, RN-184). One query whatever the number
     * of months: one pair of sums per month and the balance before the first one (RNF-030, RNF-031).
     *
     * @param  non-empty-list<CarbonImmutable>  $meses  first day of each month, in order, at most 12
     * @return list<array{mes: string, receitas: int, despesas: int, acumulado: int}>
     */
    public function handle(User $user, Carteira $carteira, array $meses): array
    {
        $primeiro = $meses[0]->startOfMonth()->toDateString();
        $fim = end($meses)->startOfMonth()->addMonth()->toDateString();

        $consulta = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.data', '<', $fim)
            ->selectRaw("coalesce(sum(case when lancamentos.data < ? then (case when lancamentos.tipo = 'receita' then cotas.valor else -cotas.valor end) else 0 end), 0) as anterior", [$primeiro]);

        foreach ($meses as $indice => $mes) {
            $intervalo = [$mes->startOfMonth()->toDateString(), $mes->startOfMonth()->addMonth()->toDateString()];

            $consulta
                ->selectRaw('coalesce(sum(case when '.self::NO_MES." and lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as ".self::RECEITAS[$indice], $intervalo)
                ->selectRaw('coalesce(sum(case when '.self::NO_MES." and lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as ".self::DESPESAS[$indice], $intervalo);
        }

        $totais = (array) $consulta->toBase()->first();
        $acumulado = (int) ($totais['anterior'] ?? 0);
        $evolucao = [];

        foreach ($meses as $indice => $mes) {
            $receitas = (int) ($totais[self::RECEITAS[$indice]] ?? 0);
            $despesas = (int) ($totais[self::DESPESAS[$indice]] ?? 0);
            $acumulado += $receitas - $despesas;

            $evolucao[] = [
                'mes' => $mes->format('Y-m'),
                'receitas' => $receitas,
                'despesas' => $despesas,
                'acumulado' => $acumulado,
            ];
        }

        return $evolucao;
    }
}
