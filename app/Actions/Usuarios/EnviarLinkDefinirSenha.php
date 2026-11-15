<?php

namespace App\Actions\Usuarios;

use App\Models\User;
use App\Notifications\DefinirSenhaNotification;
use Illuminate\Support\Facades\Password;
use Throwable;

class EnviarLinkDefinirSenha
{
    /**
     * Email a fresh set-password link; a mail failure is reported and returned instead of thrown,
     * so the administrator can resend it later (RN-135).
     */
    public function handle(User $user): bool
    {
        try {
            $user->notify(new DefinirSenhaNotification(Password::broker()->createToken($user)));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }
}
