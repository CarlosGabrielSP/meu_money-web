<?php

namespace App\Policies;

use App\Models\Lancamento;
use App\Models\User;

class LancamentoPolicy
{
    /**
     * Only the author changes the entry, its note and its attachment (RN-018, RN-079).
     */
    public function update(User $user, Lancamento $lancamento): bool
    {
        return $lancamento->user_id === $user->id;
    }

    /**
     * Only the author deletes the entry (RN-086).
     */
    public function delete(User $user, Lancamento $lancamento): bool
    {
        return $lancamento->user_id === $user->id;
    }

    /**
     * The author and every participant download the attachment (RN-018).
     */
    public function baixarAnexo(User $user, Lancamento $lancamento): bool
    {
        return $lancamento->user_id === $user->id
            || $lancamento->cotas()->where('user_id', $user->id)->exists();
    }
}
