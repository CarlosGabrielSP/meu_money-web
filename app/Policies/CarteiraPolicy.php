<?php

namespace App\Policies;

use App\Models\Carteira;
use App\Models\User;

class CarteiraPolicy
{
    /**
     * Only the owner sees and changes a wallet (RN-001). The report and the wallet settings stay the
     * owner's (RN-279, CA-203.3).
     */
    public function view(User $user, Carteira $carteira): bool
    {
        return $carteira->user_id === $user->id;
    }

    /**
     * The owner, or an accepted member while the owner is active, opens the wallet on the home
     * screen and operates its entries (RN-272, RN-284, CA-203.1, CA-203.4).
     */
    public function operar(User $user, Carteira $carteira): bool
    {
        return $carteira->user_id === $user->id || $carteira->isDelegadaA($user);
    }

    public function update(User $user, Carteira $carteira): bool
    {
        return $carteira->user_id === $user->id;
    }

    public function delete(User $user, Carteira $carteira): bool
    {
        return $carteira->user_id === $user->id;
    }

    /**
     * Only the owner invites, cancels and removes members (RN-272, CA-200.6, CA-203.6).
     */
    public function gerenciarMembros(User $user, Carteira $carteira): bool
    {
        return $carteira->user_id === $user->id;
    }
}
