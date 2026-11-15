<?php

namespace App\Policies;

use App\Models\Cota;
use App\Models\User;

class CotaPolicy
{
    /**
     * Only the owner of a share changes its wallet, category and status (RN-014, RN-078, RN-080).
     */
    public function update(User $user, Cota $cota): bool
    {
        return $cota->user_id === $user->id;
    }

    /**
     * Only a participant, never the author, changes their share through the participation form
     * (RN-080); the author changes their share through the entry form, which keeps the entry wallet
     * in sync (RN-145).
     */
    public function participar(User $user, Cota $cota): bool
    {
        return $cota->user_id === $user->id && $cota->lancamento->user_id !== $user->id;
    }

    /**
     * A participant, never the author, leaves a split (RN-081).
     */
    public function sair(User $user, Cota $cota): bool
    {
        return $cota->user_id === $user->id && $cota->lancamento->user_id !== $user->id;
    }
}
