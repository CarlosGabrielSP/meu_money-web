<?php

namespace App\Policies;

use App\Models\Carteira;
use App\Models\User;

class CarteiraPolicy
{
    /**
     * Only the owner sees and changes a wallet (RN-001).
     */
    public function view(User $user, Carteira $carteira): bool
    {
        return $carteira->user_id === $user->id;
    }

    public function update(User $user, Carteira $carteira): bool
    {
        return $carteira->user_id === $user->id;
    }

    public function delete(User $user, Carteira $carteira): bool
    {
        return $carteira->user_id === $user->id;
    }
}
