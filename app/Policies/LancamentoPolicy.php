<?php

namespace App\Policies;

use App\Models\Lancamento;
use App\Models\User;

class LancamentoPolicy
{
    /**
     * Only the author changes the entry, its note and its attachment (RN-018, RN-079); a member acts
     * as the author on entries of a delegated wallet (RN-272, CA-204.2, CA-204.5).
     */
    public function update(User $user, Lancamento $lancamento): bool
    {
        return $lancamento->user_id === $user->id || $lancamento->autorRepresentadoPor($user) !== null;
    }

    /**
     * Only the author deletes the entry (RN-086); a member deletes as the author (RN-272).
     */
    public function delete(User $user, Lancamento $lancamento): bool
    {
        return $this->update($user, $lancamento);
    }

    /**
     * The author and every participant download the attachment (RN-018), and so does a member of the
     * wallet of any of their shares (RN-272, CA-204.5).
     */
    public function baixarAnexo(User $user, Lancamento $lancamento): bool
    {
        return $lancamento->user_id === $user->id
            || $lancamento->cotas()->where('user_id', $user->id)->exists()
            || $lancamento->cotas()->whereIn('carteira_id', $user->carteirasDelegadas()->select('carteiras.id'))->exists()
            || $lancamento->autorRepresentadoPor($user) !== null;
    }
}
