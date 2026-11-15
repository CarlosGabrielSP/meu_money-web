<?php

namespace App\Policies;

use App\Models\Categoria;
use App\Models\User;

class CategoriaPolicy
{
    /**
     * Only the owner changes a user category; system categories belong to nobody (RN-031, RN-032).
     */
    public function update(User $user, Categoria $categoria): bool
    {
        return $categoria->user_id === $user->id;
    }

    public function delete(User $user, Categoria $categoria): bool
    {
        return $categoria->user_id === $user->id;
    }
}
