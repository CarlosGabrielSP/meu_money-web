<?php

namespace App\Actions\Grupos;

use App\Enums\EscopoSerie;
use App\Models\Cota;
use App\Models\Parcelamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class DesagruparCotas
{
    /**
     * Take the user's shares out of their groups (RF-093, RN-125).
     *
     * An installment with a mass scope takes out the shares of the user in the scope that are in the
     * same group as the selected one; the series model loses the group only when it is that group
     * (RN-149, CA-093.1, CA-093.6).
     *
     * @param  Collection<int, Cota>  $cotas
     */
    public function handle(User $user, Collection $cotas, EscopoSerie $escopo): void
    {
        DB::transaction(function () use ($user, $cotas, $escopo): void {
            $porSerieEGrupo = $escopo === EscopoSerie::Esta ? collect() : $cotas->toBase()
                ->filter(fn (Cota $cota): bool => $cota->grupo_id !== null && $cota->lancamento->parcelamento_id !== null)
                ->sortBy(fn (Cota $cota): int => (int) $cota->lancamento->numero_parcela)
                ->unique(fn (Cota $cota): string => "{$cota->lancamento->parcelamento_id}-{$cota->grupo_id}");

            foreach ($porSerieEGrupo as $cota) {
                $parcela = $cota->lancamento;

                /** @var Parcelamento $serie */
                $serie = $parcela->parcelamento;

                Cota::query()
                    ->where('user_id', $user->id)
                    ->where('grupo_id', $cota->grupo_id)
                    ->whereHas('lancamento', fn (Builder $query) => $query
                        ->where('parcelamento_id', $serie->id)
                        ->when($escopo === EscopoSerie::Proximas, fn (Builder $proximas) => $proximas->where('numero_parcela', '>=', $parcela->numero_parcela)))
                    ->update(['grupo_id' => null]);

                if ($serie->user_id !== $user->id) {
                    $serie->cotas()->where('user_id', $user->id)->where('grupo_id', $cota->grupo_id)->update(['grupo_id' => null]);
                } elseif ($serie->grupo_id === $cota->grupo_id) {
                    $serie->update(['grupo_id' => null]);
                }
            }

            Cota::query()->whereKey($cotas->modelKeys())->update(['grupo_id' => null]);
        });
    }
}
