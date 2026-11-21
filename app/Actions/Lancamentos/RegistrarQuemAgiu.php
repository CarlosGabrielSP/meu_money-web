<?php

namespace App\Actions\Lancamentos;

use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who-acted record of entries a member creates or changes on behalf of an owner (RN-274, RN-293,
 * RN-294, RN-299). Nothing is recorded when the owner acts.
 */
class RegistrarQuemAgiu
{
    /**
     * A member created the entries for the owner (CA-205.1, CA-205.7).
     *
     * @param  list<int>  $lancamentos
     */
    public function criou(User $ator, User $dono, array $lancamentos): void
    {
        if ($ator->id === $dono->id || $lancamentos === []) {
            return;
        }

        Lancamento::query()->whereKey($lancamentos)->update([
            'criado_por_id' => $ator->id,
            'criado_por_nome' => $ator->name,
        ]);
    }

    /**
     * A change to the entries or to the owner's shares in them (CA-205.2, CA-205.8). A member records
     * the change for the owner (RN-299); the owner, as author or as the participant the record was
     * for, clears it (CA-205.3, CA-205.11).
     *
     * @param  list<int>  $lancamentos
     */
    public function alterou(User $ator, User $dono, array $lancamentos): void
    {
        if ($lancamentos === []) {
            return;
        }

        if ($ator->id !== $dono->id) {
            Lancamento::query()->whereKey($lancamentos)->update([
                'alterado_por_id' => $ator->id,
                'alterado_por_nome' => $ator->name,
                'alterado_em' => now(),
                'alterado_para_id' => $dono->id,
            ]);

            return;
        }

        Lancamento::query()
            ->whereKey($lancamentos)
            ->whereNotNull('alterado_por_nome')
            ->where(fn (Builder $doDono) => $doDono->where('user_id', $dono->id)->orWhere('alterado_para_id', $dono->id))
            ->update([
                'alterado_por_id' => null,
                'alterado_por_nome' => null,
                'alterado_em' => null,
                'alterado_para_id' => null,
            ]);
    }
}
