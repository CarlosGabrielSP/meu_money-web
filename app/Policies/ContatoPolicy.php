<?php

namespace App\Policies;

use App\Enums\StatusContato;
use App\Models\Contato;
use App\Models\User;

class ContatoPolicy
{
    /**
     * Only the invited user answers a pending invitation from an active user (RN-061, RN-109, CA-061.3).
     */
    public function responder(User $user, Contato $contato): bool
    {
        return $contato->status === StatusContato::Pendente
            && $contato->convidado_id === $user->id
            && $contato->solicitante->desativado_em === null;
    }

    /**
     * The requester cancels a pending invitation (RN-068); either side removes an accepted contact (RN-063).
     */
    public function delete(User $user, Contato $contato): bool
    {
        return match ($contato->status) {
            StatusContato::Pendente => $contato->solicitante_id === $user->id,
            StatusContato::Aceito => in_array($user->id, [$contato->solicitante_id, $contato->convidado_id], true),
            StatusContato::Recusado => false,
        };
    }
}
