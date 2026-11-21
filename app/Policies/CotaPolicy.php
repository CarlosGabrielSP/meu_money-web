<?php

namespace App\Policies;

use App\Models\Cota;
use App\Models\User;

class CotaPolicy
{
    /**
     * Only the owner of a share changes its wallet, category and status (RN-014, RN-078, RN-080); a
     * member acts as the owner on shares of a delegated wallet (RN-272, RN-277).
     */
    public function update(User $user, Cota $cota): bool
    {
        return $cota->user_id === $user->id || $this->naCarteiraDelegada($user, $cota);
    }

    /**
     * Only a participant, never the author, changes their share through the participation form
     * (RN-080); the author changes their share through the entry form, which keeps the entry wallet
     * in sync (RN-145). A member acts as the participant owner (RN-277, CA-206.2).
     */
    public function participar(User $user, Cota $cota): bool
    {
        return $this->update($user, $cota) && $cota->lancamento->user_id !== $cota->user_id;
    }

    /**
     * A participant, never the author, leaves a split (RN-081); a member leaves for the participant
     * owner (RN-277, CA-206.2).
     */
    public function sair(User $user, Cota $cota): bool
    {
        return $this->participar($user, $cota);
    }

    private function naCarteiraDelegada(User $user, Cota $cota): bool
    {
        return $user->carteirasDelegadas()->whereKey($cota->carteira_id)->exists();
    }
}
