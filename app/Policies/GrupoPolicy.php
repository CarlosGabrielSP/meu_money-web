<?php

namespace App\Policies;

use App\Models\Grupo;
use App\Models\User;

class GrupoPolicy
{
    /**
     * Only the owner adds to, executes, renames or deletes a group (RN-121, RN-127, CA-093.5).
     */
    public function update(User $user, Grupo $grupo): bool
    {
        return $grupo->user_id === $user->id;
    }
}
