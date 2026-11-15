<?php

namespace App\Actions\Grupos;

use App\Enums\EscopoSerie;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

class AgruparCotas
{
    /**
     * Put the user's shares in a new group or in an existing compatible one (RF-090, RN-121, RN-125).
     *
     * A new group takes the wallet and type of the shares. Grouping is per user: only the user's
     * shares change (RN-127). An installment with a mass scope groups every share of the user in the
     * scope that is in the group wallet, executed or not, and records the group on the series model,
     * so later installments are born in it (RN-126, RN-148, RN-149).
     *
     * @param  Collection<int, Cota>  $cotas  shares of one type and one wallet, validated by the request
     */
    public function handle(User $user, Collection $cotas, Grupo|string $grupo, EscopoSerie $escopo): Grupo
    {
        return DB::transaction(function () use ($user, $cotas, $grupo, $escopo): Grupo {
            if (is_string($grupo)) {
                /** @var Cota $primeira */
                $primeira = $cotas->first();
                $grupo = new Grupo(['nome' => $grupo, 'tipo' => $primeira->lancamento->tipo]);
                $grupo->user_id = $user->id;
                $grupo->carteira_id = $primeira->carteira_id;
                $grupo->save();
            }

            Cota::query()->whereKey($cotas->modelKeys())->update(['grupo_id' => $grupo->id]);

            if ($escopo !== EscopoSerie::Esta) {
                foreach ($this->primeiraParcelaPorSerie($cotas) as $parcela) {
                    $this->agruparSerie($user, $parcela, $grupo, $escopo);
                }
            }

            return $grupo;
        });
    }

    /**
     * The lowest selected installment of each series, so each series is updated once.
     *
     * @param  Collection<int, Cota>  $cotas
     * @return SupportCollection<int, Lancamento>
     */
    private function primeiraParcelaPorSerie(Collection $cotas): SupportCollection
    {
        return $cotas->toBase()
            ->map(fn (Cota $cota): Lancamento => $cota->lancamento)
            ->filter(fn (Lancamento $parcela): bool => $parcela->parcelamento_id !== null)
            ->sortBy('numero_parcela')
            ->unique('parcelamento_id')
            ->values();
    }

    private function agruparSerie(User $user, Lancamento $parcela, Grupo $grupo, EscopoSerie $escopo): void
    {
        /** @var Parcelamento $serie */
        $serie = $parcela->parcelamento;

        Cota::query()
            ->where('user_id', $user->id)
            ->where('carteira_id', $grupo->carteira_id)
            ->whereHas('lancamento', fn (Builder $query) => $query
                ->where('parcelamento_id', $serie->id)
                ->when($escopo === EscopoSerie::Proximas, fn (Builder $proximas) => $proximas->where('numero_parcela', '>=', $parcela->numero_parcela)))
            ->update(['grupo_id' => $grupo->id]);

        if ($serie->user_id !== $user->id) {
            $serie->cotas()->where('user_id', $user->id)->update(['grupo_id' => $grupo->id]);
        } elseif ($serie->carteira_id === $grupo->carteira_id) {
            $serie->update(['grupo_id' => $grupo->id]);
        }
    }
}
