<?php

namespace App\Actions\Usuarios;

use App\Models\User;
use Illuminate\Validation\ValidationException;

class GarantirAdministradorAtivo
{
    /**
     * Abort when the change would leave the system without an active administrator (RN-111).
     *
     * Call it inside the same transaction as the write: active administrator rows are locked so
     * two concurrent demotions cannot both pass the check.
     *
     * @throws ValidationException
     */
    public function handle(User $user, string $campoErro = 'usuario'): void
    {
        if (! $user->is_admin || $user->desativado_em !== null) {
            return;
        }

        $outrosAdministradoresAtivos = User::query()
            ->where('is_admin', true)
            ->whereNull('desativado_em')
            ->whereKeyNot($user->getKey())
            ->lockForUpdate()
            ->exists();

        if (! $outrosAdministradoresAtivos) {
            throw ValidationException::withMessages([
                $campoErro => 'O sistema precisa de ao menos um administrador ativo.',
            ]);
        }
    }
}
