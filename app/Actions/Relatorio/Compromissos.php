<?php

namespace App\Actions\Relatorio;

use App\Actions\Cartoes\CalcularFaturas;
use App\Enums\Periodicidade;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class Compromissos
{
    public function __construct(private CalcularFaturas $calcularFaturas) {}

    /**
     * Expense series that still bind the user's shares in the wallet, independent of the period
     * (RN-185): installment series with a pending share and active recurring series. Two queries. In a
     * card, a share is paid when its invoice is settled (RN-214, CA-172.5), one more query.
     *
     * @return array{parceladas: array{series: list<array{id: int, descricao: string, parcelasPendentes: int, restante: int, ultimaParcela: string}>, total: int}, recorrentes: array{series: list<array{id: int, descricao: string, proximoValor: int, periodicidade: string, mensal: int}>, total: int}}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $hoje): array
    {
        $quitadas = $carteira->isCartao() ? CalcularFaturas::quitadas($this->calcularFaturas->handle($user, [$carteira->id])) : [];
        $parceladas = $this->parceladas($user, $carteira, $quitadas);
        $recorrentes = $this->recorrentes($user, $carteira, $hoje, $quitadas);

        return [
            'parceladas' => ['series' => $parceladas, 'total' => array_sum(array_column($parceladas, 'restante'))],
            'recorrentes' => ['series' => $recorrentes, 'total' => array_sum(array_column($recorrentes, 'mensal'))],
        ];
    }

    /**
     * Installment series with a pending share: pending installments, the remaining amount and the
     * month of the user's last installment, paid or not (RN-185 a). Rows come by series and invoice,
     * so the shares of a settled invoice count as paid (RN-214).
     *
     * @param  list<int>  $quitadas  settled invoices of a card
     * @return list<array{id: int, descricao: string, parcelasPendentes: int, restante: int, ultimaParcela: string}>
     */
    private function parceladas(User $user, Carteira $carteira, array $quitadas): array
    {
        $series = [];

        $linhas = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->join('parcelamentos', 'parcelamentos.id', '=', 'lancamentos.parcelamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.tipo', 'despesa')
            ->whereNotNull('parcelamentos.total_parcelas')
            ->groupBy('parcelamentos.id', 'parcelamentos.descricao', 'cotas.fatura_id')
            ->selectRaw('parcelamentos.id, parcelamentos.descricao, cotas.fatura_id')
            ->selectRaw('sum(case when cotas.executado then 0 else 1 end) as pendentes')
            ->selectRaw('sum(case when cotas.executado then 0 else cotas.valor end) as restante')
            ->selectRaw('max(lancamentos.data) as ultima')
            ->toBase()
            ->get();

        foreach ($linhas as $linha) {
            $id = (int) $linha->id;
            $paga = $linha->fatura_id !== null && in_array((int) $linha->fatura_id, $quitadas, true);
            $series[$id] ??= ['id' => $id, 'descricao' => (string) $linha->descricao, 'parcelasPendentes' => 0, 'restante' => 0, 'ultima' => ''];
            $series[$id]['parcelasPendentes'] += $paga ? 0 : (int) $linha->pendentes;
            $series[$id]['restante'] += $paga ? 0 : (int) $linha->restante;
            $series[$id]['ultima'] = max($series[$id]['ultima'], (string) $linha->ultima);
        }

        $pendentes = array_filter($series, fn (array $serie): bool => $serie['parcelasPendentes'] > 0);
        usort($pendentes, fn (array $a, array $b): int => [$a['ultima'], $a['descricao']] <=> [$b['ultima'], $b['descricao']]);

        return array_map(fn (array $serie): array => [
            'id' => $serie['id'],
            'descricao' => $serie['descricao'],
            'parcelasPendentes' => $serie['parcelasPendentes'],
            'restante' => $serie['restante'],
            'ultimaParcela' => substr($serie['ultima'], 0, 7),
        ], $pendentes);
    }

    /**
     * Recurring series with a pending share dated today or later: the amount of the next one, the
     * periodicity and the monthly equivalent, rounded down to the cent (RN-185 b).
     *
     * @param  list<int>  $quitadas  settled invoices of a card
     * @return list<array{id: int, descricao: string, proximoValor: int, periodicidade: string, mensal: int}>
     */
    private function recorrentes(User $user, Carteira $carteira, CarbonImmutable $hoje, array $quitadas): array
    {
        $proximasCotas = fn (): Builder => Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->whereColumn('lancamentos.parcelamento_id', 'parcelamentos.id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('cotas.executado', false)
            ->when($quitadas !== [], fn (Builder $query) => $query->where(fn (Builder $aberta) => $aberta
                ->whereNull('cotas.fatura_id')
                ->orWhereNotIn('cotas.fatura_id', $quitadas)))
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
