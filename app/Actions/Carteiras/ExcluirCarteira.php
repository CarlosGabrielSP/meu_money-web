<?php

namespace App\Actions\Carteiras;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExcluirCarteira
{
    /**
     * Delete a non-default wallet, moving its contents to another wallet or deleting them
     * (RN-006, RN-007, RN-132, RN-137).
     *
     * Shared entries (RN-086 and RN-081 inside the cascade, CA-024.4, CA-024.5) are part of phase 6.
     *
     * @throws ValidationException
     */
    public function handle(Carteira $carteira, ?Carteira $destino = null): void
    {
        DB::transaction(function () use ($carteira, $destino): void {
            $padrao = Carteira::query()->whereKey($carteira->id)->lockForUpdate()->value('padrao');

            if ($padrao) {
                throw ValidationException::withMessages([
                    'carteira' => 'A carteira padrão não pode ser excluída. Defina outra carteira como padrão antes.',
                ]);
            }

            if ($destino !== null) {
                $this->moverConteudo($carteira, $destino);
            } else {
                $this->excluirConteudo($carteira);
            }

            $carteira->delete();
        });
    }

    private function moverConteudo(Carteira $carteira, Carteira $destino): void
    {
        $carteira->cotas()->update(['carteira_id' => $destino->id]);
        $carteira->grupos()->update(['carteira_id' => $destino->id]);
        $carteira->parcelamentos()->update(['carteira_id' => $destino->id]);
    }

    /**
     * @throws ValidationException
     */
    private function excluirConteudo(Carteira $carteira): void
    {
        $lancamentoIds = Cota::query()->where('carteira_id', $carteira->id)->pluck('lancamento_id');

        $temCompartilhados = Lancamento::query()->whereKey($lancamentoIds)->whereNotNull('modo_rateio')->exists()
            || $carteira->parcelamentos()->whereNotNull('modo_rateio')->exists();

        if ($temCompartilhados) {
            throw ValidationException::withMessages([
                'carteira' => 'A carteira tem lançamentos compartilhados. Mova os lançamentos para outra carteira.',
            ]);
        }

        Lancamento::query()->whereKey($lancamentoIds)->delete();

        $serieIds = $carteira->parcelamentos()->pluck('id');

        Lancamento::query()->whereIn('parcelamento_id', $serieIds)->update(['numero_parcela' => null]);
        Parcelamento::query()->whereKey($serieIds)->delete();
    }
}
