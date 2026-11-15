<?php

namespace App\Policies;

use App\Models\Cota;
use App\Models\User;

class CotaPolicy
{
    /**
     * Only the owner of a share changes its status (RN-014).
     */
    public function update(User $user, Cota $cota): bool
    {
        return $cota->user_id === $user->id;
    }
}
