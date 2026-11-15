<?php

namespace App\Actions\Contatos;

use App\Enums\StatusContato;
use App\Models\Contato;
use App\Models\User;
use App\Notifications\ConviteContatoNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class EnviarConvite
{
    /**
     * Invite an active user by email (RF-060). The caller always shows the same generic message, so
     * nothing reveals whether the email has an account (RN-065).
     *
     * One record per pair (RN-099, RN-141): an accepted pair or a pending invitation from the
     * requester stays as it is (CA-060.7); a pending invitation from the other side is accepted
     * (RN-098); a refused one, in either direction, is reopened as pending from the requester
     * (RN-067, CA-060.5, CA-060.8). The email goes after the response is sent, so the response time
     * does not reveal whether the account exists; a mail failure is reported, never shown (RN-065).
     */
    public function handle(User $solicitante, string $email): void
    {
        $convidado = User::query()
            ->where('email', Str::lower($email))
            ->whereNull('desativado_em')
            ->whereKeyNot($solicitante->id)
            ->first();

        if ($convidado === null) {
            return;
        }

        try {
            $notificar = $this->registrar($solicitante, $convidado);
        } catch (UniqueConstraintViolationException) {
            return;
        }

        if ($notificar) {
            defer(function () use ($convidado, $solicitante): void {
                try {
                    $convidado->notify(new ConviteContatoNotification($solicitante));
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }
    }

    /**
     * Create or update the pair record and tell whether the invited user must be emailed.
     *
     * Both users are locked in id order first, so two invitations of the same pair at once are
     * serialized and never create two records. A concurrent insert that still hits the unique
     * index means the record already exists, which changes nothing (CA-060.7).
     */
    private function registrar(User $solicitante, User $convidado): bool
    {
        return DB::transaction(function () use ($solicitante, $convidado): bool {
            User::query()->whereKey([$solicitante->id, $convidado->id])->orderBy('id')->lockForUpdate()->get(['id']);

            $contato = Contato::query()->doPar($solicitante, $convidado)->lockForUpdate()->first();

            if ($contato === null) {
                $contato = new Contato(['status' => StatusContato::Pendente]);
                $contato->forceFill(['solicitante_id' => $solicitante->id, 'convidado_id' => $convidado->id])->save();

                return true;
            }

            $doSolicitante = $contato->solicitante_id === $solicitante->id;

            return match (true) {
                $contato->status === StatusContato::Aceito => false,
                $contato->status === StatusContato::Pendente && $doSolicitante => false,
                $contato->status === StatusContato::Pendente => $this->aceitar($contato),
                default => $this->reabrir($contato, $solicitante, $convidado),
            };
        });
    }

    private function aceitar(Contato $contato): bool
    {
        $contato->update(['status' => StatusContato::Aceito, 'respondido_em' => now()]);

        return false;
    }

    private function reabrir(Contato $contato, User $solicitante, User $convidado): bool
    {
        $contato->forceFill([
            'solicitante_id' => $solicitante->id,
            'convidado_id' => $convidado->id,
            'status' => StatusContato::Pendente,
            'respondido_em' => null,
        ])->save();

        return true;
    }
}
