<?php

namespace App\Actions\Carteiras;

use App\Enums\StatusMembro;
use App\Models\Carteira;
use App\Models\CarteiraMembro;
use App\Models\User;
use App\Notifications\ConviteMembroNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConvidarMembro
{
    /**
     * Invite a contact to the wallet and tell whether an invitation was sent (RN-270, RN-289).
     *
     * One record per wallet and user: a member or a pending invitation stays as it is (CA-200.4);
     * a refused invitation is reopened as pending and emailed again (CA-200.5). The wallet is locked,
     * so two invitations at once are serialized; an insert that still hits the unique index means
     * the record already exists. The email goes after the response; a failure is reported only.
     */
    public function handle(Carteira $carteira, User $convidado): bool
    {
        try {
            $enviado = DB::transaction(function () use ($carteira, $convidado): bool {
                Carteira::query()->whereKey($carteira->id)->lockForUpdate()->first(['id']);

                $membro = $carteira->membros()->where('user_id', $convidado->id)->first();

                if ($membro === null) {
                    $membro = new CarteiraMembro(['status' => StatusMembro::Pendente]);
                    $membro->forceFill(['carteira_id' => $carteira->id, 'user_id' => $convidado->id])->save();

                    return true;
                }

                if ($membro->status !== StatusMembro::Recusado) {
                    return false;
                }

                $membro->update(['status' => StatusMembro::Pendente, 'respondido_em' => null]);

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        if ($enviado) {
            $dono = $carteira->user;

            defer(function () use ($convidado, $carteira, $dono): void {
                try {
                    $convidado->notify(new ConviteMembroNotification($dono, $carteira));
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }

        return $enviado;
    }
}
