<?php

namespace App\Actions\Cartoes;

use App\Models\Cartao;
use App\Models\Fatura;
use App\Models\User;
use Carbon\CarbonImmutable;

class CartoesDoFormulario
{
    /**
     * What the entry form needs to tell the invoice of a date in each card of the user (RN-192,
     * RN-232, nota 07): due day and closing days for the default dates, the dates of open and future
     * invoices edited one by one, and the first day a new purchase may have (the start of the open
     * invoice). Two queries whatever the number of cards. A member gets only the owner's cards
     * delegated to the member, passed as wallet ids (RN-321, RN-331).
     *
     * @param  list<int>|null  $somenteCarteiras  wallet ids to keep; null keeps every card of the user
     * @return array<int, array{diaVencimento: int, diasFechamento: int, abertaDesde: string, editadas: list<array{referencia: string, fechamento: string, vencimento: string}>}> by card wallet id
     */
    public function handle(User $user, CarbonImmutable $hoje, ?array $somenteCarteiras = null): array
    {
        $cartoes = Cartao::query()
            ->whereHas('carteira', fn ($carteira) => $carteira->where('user_id', $user->id))
            ->when($somenteCarteiras !== null, fn ($cartoes) => $cartoes->whereIn('carteira_id', $somenteCarteiras))
            ->get();

        if ($cartoes->isEmpty()) {
            return [];
        }

        $faturas = Fatura::query()
            ->whereIn('carteira_id', $cartoes->pluck('carteira_id'))
            ->where('referencia', '>=', $hoje->startOfMonth()->subMonthsNoOverflow(2)->toDateString())
            ->get()
            ->groupBy('carteira_id');

        return $cartoes->mapWithKeys(function (Cartao $cartao) use ($faturas, $hoje): array {
            $doCartao = $faturas->get($cartao->carteira_id, collect());
            $calendario = new FaturasDoCartao($cartao, $doCartao);

            return [$cartao->carteira_id => [
                'diaVencimento' => $cartao->dia_vencimento,
                'diasFechamento' => $cartao->dias_fechamento,
                'abertaDesde' => $calendario->inicioDoPeriodo($calendario->aberta($hoje))->toDateString(),
                'editadas' => array_values($doCartao
                    ->filter(fn (Fatura $fatura): bool => $fatura->datas_editadas && ! $fatura->isFechada($hoje))
                    ->map(fn (Fatura $fatura): array => [
                        'referencia' => $fatura->referencia->format('Y-m'),
                        'fechamento' => $fatura->fechamento->toDateString(),
                        'vencimento' => $fatura->vencimento->toDateString(),
                    ])
                    ->all()),
            ]];
        })->all();
    }
}
