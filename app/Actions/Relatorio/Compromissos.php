<?php

namespace App\Actions\Relatorio;

use App\Enums\Periodicidade;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class Compromissos
{
    /**
     * Expense series that still bind the user's shares in the wallet, independent of the period
     * (RN-185): installment series with a pending share and active recurring series. Two queries.
     *
     * @return array{parceladas: array{series: list<array{id: int, descricao: string, parcelasPendentes: int, restante: int, ultimaParcela: string}>, total: int}, recorrentes: array{series: list<array{id: int, descricao: string, proximoValor: int, periodicidade: string, mensal: int}>, total: int}}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $hoje): array
    {
        $parceladas = $this->parceladas($user, $carteira);
        $recorrentes = $this->recorrentes($user, $carteira, $hoje);

        return [
            'parceladas' => ['series' => $parceladas, 'total' => array_sum(array_column($parceladas, 'restante'))],
            'recorrentes' => ['series' => $recorrentes, 'total' => array_sum(array_column($recorrentes, 'mensal'))],
        ];
    }

    /**
     * Installment series with a pending share: pending installments, the remaining amount and the
     * month of the user's last installment, paid or not (RN-185 a).
     *
     * @return list<array{id: int, descricao: string, parcelasPendentes: int, restante: int, ultimaParcela: string}>
     */
    private function parceladas(User $user, Carteira $carteira): array
    {
        return array_values(Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->join('parcelamentos', 'parcelamentos.id', '=', 'lancamentos.parcelamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.tipo', 'despesa')
            ->whereNotNull('parcelamentos.total_parcelas')
            ->groupBy('parcelamentos.id', 'parcelamentos.descricao')
            ->selectRaw('parcelamentos.id, parcelamentos.descricao')
            ->selectRaw('sum(case when cotas.executado then 0 else 1 end) as pendentes')
            ->selectRaw('sum(case when cotas.executado then 0 else cotas.valor end) as restante')
            ->selectRaw('max(lancamentos.data) as ultima')
            ->havingRaw('sum(case when cotas.executado then 0 else 1 end) > 0')
            ->orderBy('ultima')
            ->orderBy('parcelamentos.descricao')
            ->toBase()
            ->get()
            ->map(fn (object $serie): array => [
                'id' => (int) $serie->id,
                'descricao' => (string) $serie->descricao,
                'parcelasPendentes' => (int) $serie->pendentes,
                'restante' => (int) $serie->restante,
                'ultimaParcela' => substr((string) $serie->ultima, 0, 7),
            ])
            ->all());
    }

    /**
     * Recurring series with a pending share dated today or later: the amount of the next one, the
     * periodicity and the monthly equivalent, rounded down to the cent (RN-185 b).
     *
     * @return list<array{id: int, descricao: string, proximoValor: int, periodicidade: string, mensal: int}>
     */
    private function recorrentes(User $user, Carteira $carteira, CarbonImmutable $hoje): array
    {
        $proximasCotas = fn (): Builder => Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->whereColumn('lancamentos.parcelamento_id', 'parcelamentos.id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('cotas.executado', false)
            ->where('lancamentos.data', '>=', $hoje->toDateString());

        return array_values(Parcelamento::query()
            ->whereNull('total_parcelas')
            ->where('tipo', 'despesa')
            ->whereExists($proximasCotas()->toBase())
            ->select(['parcelamentos.id', 'parcelamentos.descricao', 'parcelamentos.periodicidade'])
            ->addSelect(['proximo_valor' => $proximasCotas()
                ->select('cotas.valor')
                ->orderBy('lancamentos.data')
                ->limit(1)])
            ->orderBy('descricao')
            ->toBase()
            ->get()
            ->map(function (object $serie): array {
                $periodicidade = Periodicidade::from((string) $serie->periodicidade);

                return [
                    'id' => (int) $serie->id,
                    'descricao' => (string) $serie->descricao,
                    'proximoValor' => (int) $serie->proximo_valor,
                    'periodicidade' => $periodicidade->value,
                    'mensal' => intdiv((int) $serie->proximo_valor, $periodicidade->meses()),
                ];
            })
            ->all());
    }
}
