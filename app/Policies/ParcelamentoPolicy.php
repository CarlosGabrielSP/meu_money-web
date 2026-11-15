<?php

namespace App\Policies;

use App\Models\Parcelamento;
use App\Models\User;

class ParcelamentoPolicy
{
    /**
     * Only the author changes a series (RN-079).
     */
    public function update(User $user, Parcelamento $parcelamento): bool
    {
        return $parcelamento->user_id === $user->id;
    }
}
