<?php

namespace App\Actions\Inicio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class PendenciasDaCarteira
{
    /**
     * Rows shown in the panel of overdue shares (RN-172).
     */
    public const int LIMITE_VENCIDAS = 50;

    /**
     * Days ahead, today included, of the upcoming expenses (RN-173).
     */
    public const int DIAS_PROXIMOS = 7;

    /**
     * The two highlights in one scan of the pending shares: count and sum by type of those dated
     * before today, from any month and regardless of filters (RN-172), and count and sum of the
     * expenses from today to six days ahead (RN-173).
     *
     * @return array{vencidas: array{quantidade: int, receitas: int, despesas: int}, proximas: array{quantidade: int, total: int}}
     */
    public function resumo(User $user, Carteira $carteira, CarbonImmutable $hoje): array
    {
        $vencida = 'lancamentos.data < ?';
        $proxima = "lancamentos.tipo = 'despesa' and lancamentos.data >= ?";
        $hojeTexto = $hoje->toDateString();

        $resumo = $this->pendentes($user, $carteira)
            ->where('lancamentos.data', '<', $hoje->addDays(self::DIAS_PROXIMOS)->toDateString())
            ->selectRaw("coalesce(sum(case when {$vencida} then 1 else 0 end), 0) as vencidas", [$hojeTexto])
            ->selectRaw("coalesce(sum(case when {$vencida} and lancamentos.tipo = 'receita' then cotas.valor else 0 end), 0) as vencidas_receitas", [$hojeTexto])
            ->selectRaw("coalesce(sum(case when {$vencida} and lancamentos.tipo = 'despesa' then cotas.valor else 0 end), 0) as vencidas_despesas", [$hojeTexto])
            ->selectRaw("coalesce(sum(case when {$proxima} then 1 else 0 end), 0) as proximas", [$hojeTexto])
            ->selectRaw("coalesce(sum(case when {$proxima} then cotas.valor else 0 end), 0) as proximas_total", [$hojeTexto])
            ->toBase()
            ->first();

        return [
            'vencidas' => [
                'quantidade' => (int) $resumo?->vencidas,
                'receitas' => (int) $resumo?->vencidas_receitas,
                'despesas' => (int) $resumo?->vencidas_despesas,
            ],
            'proximas' => [
                'quantidade' => (int) $resumo?->proximas,
                'total' => (int) $resumo?->proximas_total,
            ],
        ];
    }

    /**
     * The overdue shares of the panel, most recent first, up to the limit (RN-172).
     *
     * @return list<array{cotaId: int, data: string, descricao: string, tipo: string, valor: int}>
     */
    public function listaVencidas(User $user, Carteira $carteira, CarbonImmutable $hoje): array
    {
        return $this->linhas(
            $this->vencidasQuery($user, $carteira, $hoje)
                ->orderByDesc('lancamentos.data')
                ->orderByDesc('lancamentos.id')
                ->limit(self::LIMITE_VENCIDAS),
        );
    }

    /**
     * The upcoming expenses of the panel, soonest first (RN-173).
     *
     * @return list<array{cotaId: int, data: string, descricao: string, tipo: string, valor: int}>
     */
    public function listaProximas(User $user, Carteira $carteira, CarbonImmutable $hoje): array
    {
        return $this->linhas(
            $this->proximasQuery($user, $carteira, $hoje)
                ->orderBy('lancamentos.data')
                ->orderBy('lancamentos.id'),
        );
    }

    /**
     * Dates are compared with bare "Y-m-d" strings: the date cast stores "Y-m-d 00:00:00", which is
     * not lower than today's string, so today is never overdue.
     *
     * @return Builder<Cota>
     */
    private function vencidasQuery(User $user, Carteira $carteira, CarbonImmutable $hoje): Builder
    {
        return $this->pendentes($user, $carteira)
            ->where('lancamentos.data', '<', $hoje->toDateString());
    }

    /**
     * @return Builder<Cota>
     */
    private function proximasQuery(User $user, Carteira $carteira, CarbonImmutable $hoje): Builder
    {
        return $this->pendentes($user, $carteira)
            ->where('lancamentos.tipo', 'despesa')
            ->where('lancamentos.data', '>=', $hoje->toDateString())
            ->where('lancamentos.data', '<', $hoje->addDays(self::DIAS_PROXIMOS)->toDateString());
    }

    /**
     * @return Builder<Cota>
     */
    private function pendentes(User $user, Carteira $carteira): Builder
    {
        return Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('cotas.executado', false);
    }

    /**
     * @param  Builder<Cota>  $query
     * @return list<array{cotaId: int, data: string, descricao: string, tipo: string, valor: int}>
     */
    private function linhas(Builder $query): array
    {
        return array_values($query
            ->select(['cotas.id', 'cotas.valor', 'lancamentos.data', 'lancamentos.descricao', 'lancamentos.tipo'])
            ->toBase()
            ->get()
            ->map(fn (object $linha): array => [
                'cotaId' => (int) $linha->id,
                'data' => CarbonImmutable::parse($linha->data)->toDateString(),
                'descricao' => (string) $linha->descricao,
                'tipo' => (string) $linha->tipo,
                'valor' => (int) $linha->valor,
            ])
            ->all());
    }
}
