<?php

namespace App\Actions\Inicio;

use App\Actions\Cartoes\CalcularFaturas;
use App\Actions\Cartoes\SaldosVinculados;
use App\Enums\TipoCarteira;
use App\Models\Carteira;
use App\Models\Fatura;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;

class SaldosDasCarteiras
{
    public function __construct(
        private SaldosVinculados $saldosVinculados,
        private CalcularFaturas $calcularFaturas,
    ) {}

    /**
     * Current accumulated balance of every common wallet of the user until the last day of the month,
     * and the to pay of the invoice of the month for each card (RN-022, RN-023, RN-171, RN-225). A
     * constant number of queries whatever the number of wallets (RNF-030).
     *
     * @param  array<int, list<array{fatura: Fatura, aPagar: int}>>|null  $faturas  CalcularFaturas, to reuse in the request
     * @param  array<int, array{pagamentosAte: int, aPagar: int}>|null  $vinculados  SaldosVinculados of the month, to reuse
     * @return array<int, int> amount in cents by wallet id
     */
    public function handle(User $user, CarbonImmutable $mes, ?array $faturas = null, ?array $vinculados = null): array
    {
        $saldos = array_map(fn (array $saldos): int => $saldos['atual'], $this->atuaisEEstimados($user, $mes, $vinculados));

        foreach ($faturas ?? $this->calcularFaturas->handle($user) as $cartaoId => $doCartao) {
            $saldos[$cartaoId] = CalcularFaturas::aPagarNoMes($doCartao, $mes);
        }

        return $saldos;
    }

    /**
     * Current and estimated accumulated balance of every common wallet of the user until the last day
     * of the month, in one grouped query plus the card changes (RN-021, RN-022, RN-186, RN-196): the
     * current balance loses the invoice payments and the estimated one also what the invoices still
     * have to pay (data model 05, R5). A wallet without shares gets 0; cards get 0 here, since their
     * balance is the invoice (RN-225, RN-227).
     *
     * @param  array<int, array{pagamentosAte: int, aPagar: int}>|null  $vinculados  SaldosVinculados of the month, to reuse
     * @return array<int, array{atual: int, estimado: int}> balances in cents by wallet id
     */
    public function atuaisEEstimados(User $user, CarbonImmutable $mes, ?array $vinculados = null): array
    {
        $comSinal = "case when lancamentos.id is null then 0 when lancamentos.tipo = 'receita' then cotas.valor else -cotas.valor end";
        $cartoes = $vinculados ?? $this->saldosVinculados->handle($user, $mes);

        return Carteira::query()
            ->where('carteiras.user_id', $user->id)
            ->leftJoin('cotas', fn (JoinClause $join) => $join
                ->on('cotas.carteira_id', '=', 'carteiras.id')
                ->where('cotas.user_id', $user->id))
            ->leftJoin('lancamentos', fn (JoinClause $join) => $join
                ->on('lancamentos.id', '=', 'cotas.lancamento_id')
                ->where('lancamentos.data', '<', $mes->startOfMonth()->addMonth()->toDateString()))
            ->groupBy('carteiras.id', 'carteiras.tipo')
            ->select('carteiras.id', 'carteiras.tipo')
            ->selectRaw("coalesce(sum(case when cotas.executado then {$comSinal} else 0 end), 0) as atual")
            ->selectRaw("coalesce(sum({$comSinal}), 0) as estimado")
            ->toBase()
            ->get()
            ->mapWithKeys(function (object $carteira) use ($cartoes): array {
                if ($carteira->tipo === TipoCarteira::Cartao->value) {
                    return [(int) $carteira->id => ['atual' => 0, 'estimado' => 0]];
                }

                $doCartao = $cartoes[(int) $carteira->id] ?? ['pagamentosAte' => 0, 'aPagar' => 0];

                return [(int) $carteira->id => [
                    'atual' => (int) $carteira->atual - $doCartao['pagamentosAte'],
                    'estimado' => (int) $carteira->estimado - $doCartao['pagamentosAte'] - $doCartao['aPagar'],
                ]];
            })
            ->all();
    }
}
