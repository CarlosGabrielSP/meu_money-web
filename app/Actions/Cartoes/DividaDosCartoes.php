<?php

namespace App\Actions\Cartoes;

use App\Enums\TipoCarteira;
use App\Models\Cota;
use App\Models\PagamentoFatura;
use App\Models\User;
use Carbon\CarbonImmutable;

class DividaDosCartoes
{
    /**
     * Column aliases of each day, at most 14; literal so the SQL stays literal.
     */
    private const array DIAS = ['d0', 'd1', 'd2', 'd3', 'd4', 'd5', 'd6', 'd7', 'd8', 'd9', 'd10', 'd11', 'd12', 'd13'];

    /**
     * Debt of each card of the user at the end of each given day, as a negative amount (RN-227, R9,
     * DEC-087): purchases minus income dated until that day, by purchase date, minus the payments
     * dated until that day. Two queries whatever the number of cards and days (RNF-030).
     *
     * @param  list<CarbonImmutable>  $datas  at most 14
     * @param  list<int>|null  $cartoes  card wallet ids, or every card of the user
     * @return array<int, list<int>> debt in cents by card wallet id, one per day in order
     */
    public function handle(User $user, array $datas, ?array $cartoes = null): array
    {
        $limites = array_map(fn (CarbonImmutable $data): string => $data->startOfDay()->addDay()->toDateString(), $datas);

        $cotas = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->join('carteiras', 'carteiras.id', '=', 'cotas.carteira_id')
            ->where('carteiras.user_id', $user->id)
            ->where('carteiras.tipo', TipoCarteira::Cartao->value)
            ->where('cotas.user_id', $user->id)
            ->when($cartoes !== null, fn ($query) => $query->whereIn('cotas.carteira_id', $cartoes))
            ->groupBy('cotas.carteira_id')
            ->select('cotas.carteira_id as cartao_id');

        $pagamentos = PagamentoFatura::query()
            ->join('faturas', 'faturas.id', '=', 'pagamentos_fatura.fatura_id')
            ->join('carteiras', 'carteiras.id', '=', 'faturas.carteira_id')
            ->where('carteiras.user_id', $user->id)
            ->when($cartoes !== null, fn ($query) => $query->whereIn('faturas.carteira_id', $cartoes))
            ->groupBy('faturas.carteira_id')
            ->select('faturas.carteira_id as cartao_id');

        foreach ($limites as $indice => $limite) {
            $cotas->selectRaw("coalesce(sum(case when lancamentos.data < ? then (case when lancamentos.tipo = 'despesa' then cotas.valor else -cotas.valor end) else 0 end), 0) as ".self::DIAS[$indice], [$limite]);
            $pagamentos->selectRaw('coalesce(sum(case when pagamentos_fatura.data < ? then pagamentos_fatura.valor else 0 end), 0) as '.self::DIAS[$indice], [$limite]);
        }

        $dividas = [];

        foreach ($cotas->toBase()->get() as $linha) {
            foreach (array_keys($limites) as $indice) {
                $dividas[(int) $linha->cartao_id][$indice] = -(int) $linha->{self::DIAS[$indice]};
            }
        }

        foreach ($pagamentos->toBase()->get() as $linha) {
            foreach (array_keys($limites) as $indice) {
                $dividas[(int) $linha->cartao_id][$indice] = ($dividas[(int) $linha->cartao_id][$indice] ?? 0) + (int) $linha->{self::DIAS[$indice]};
            }
        }

        return array_map(fn (array $porDia): array => array_values(array_replace(array_fill(0, count($limites), 0), $porDia)), $dividas);
    }
}
