<?php

namespace App\Policies;

use App\Models\Parcelamento;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class ParcelamentoPolicy
{
    /**
     * Any user with a share in the series sees its tracking (RN-213).
     */
    public function view(User $user, Parcelamento $parcelamento): bool
    {
        return $parcelamento->lancamentos()
            ->whereHas('cotas', fn (Builder $cotas) => $cotas->where('user_id', $user->id))
            ->exists();
    }

    /**
     * Only the author changes a series (RN-079).
     */
    public function update(User $user, Parcelamento $parcelamento): bool
    {
        return $parcelamento->user_id === $user->id;
    }
}
