<?php

namespace App\Actions\Relatorio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;

class DespesasPorTipo
{
    /**
     * Estimated expenses of the user's shares in the wallet in the period by kind: one-off (entry
     * without a series), installments and recurring, with the share of the total, in one query (RN-183).
     *
     * @return list<array{tipo: 'avista'|'parceladas'|'recorrentes', valor: int, percentual: float|null}>
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $totais = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->leftJoin('parcelamentos', 'parcelamentos.id', '=', 'lancamentos.parcelamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.tipo', 'despesa')
            ->where('lancamentos.data', '>=', $inicio->toDateString())
            ->where('lancamentos.data', '<', $fim->toDateString())
            ->selectRaw('coalesce(sum(case when parcelamentos.id is null then cotas.valor else 0 end), 0) as avista')
            ->selectRaw('coalesce(sum(case when parcelamentos.total_parcelas is not null then cotas.valor else 0 end), 0) as parceladas')
            ->selectRaw('coalesce(sum(case when parcelamentos.id is not null and parcelamentos.total_parcelas is null then cotas.valor else 0 end), 0) as recorrentes')
            ->toBase()
            ->first();

        $valores = [
            'avista' => (int) $totais?->avista,
            'parceladas' => (int) $totais?->parceladas,
            'recorrentes' => (int) $totais?->recorrentes,
        ];
        $total = array_sum($valores);

        return array_map(
            fn (string $tipo): array => ['tipo' => $tipo, 'valor' => $valores[$tipo], 'percentual' => Porcentagem::de($valores[$tipo], $total)],
            array_keys($valores),
        );
    }
}
