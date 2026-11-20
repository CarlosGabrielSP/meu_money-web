<?php

namespace App\Policies;

use App\Enums\StatusMembro;
use App\Models\CarteiraMembro;
use App\Models\User;

class CarteiraMembroPolicy
{
    /**
     * Only the invited user answers a pending invitation from an active owner (RN-270, RN-288,
     * CA-201.4).
     */
    public function responder(User $user, CarteiraMembro $membro): bool
    {
        return $membro->status === StatusMembro::Pendente
            && $membro->user_id === $user->id
            && $membro->carteira->user->desativado_em === null;
    }

    /**
     * The owner cancels a pending invitation or removes a member; the member leaves (RN-270, RN-282,
     * CA-202.1 to CA-202.3). A refused invitation is not deleted; the owner invites again.
     */
    public function delete(User $user, CarteiraMembro $membro): bool
    {
        $dono = $membro->carteira->user_id === $user->id;

        return match ($membro->status) {
            StatusMembro::Pendente => $dono,
            StatusMembro::Aceito => $dono || $membro->user_id === $user->id,
            StatusMembro::Recusado => false,
        };
    }
}
