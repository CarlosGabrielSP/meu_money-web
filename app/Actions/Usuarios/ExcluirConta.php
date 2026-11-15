<?php

namespace App\Actions\Usuarios;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExcluirConta
{
    public function __construct(private GarantirAdministradorAtivo $garantirAdministradorAtivo) {}

    /**
     * Delete the user and all of their data (RN-112, RN-113).
     *
     * Dependents are removed in an explicit order because wallets and categories are referenced
     * with RESTRICT by shares and series, and the database does not guarantee cascade order.
     * Attachment files live under anexos/{user_id} and are removed after the commit.
     * Converting shares of shared entries (RN-113, CA-007.3, CA-007.4) is part of phase 6.
     */
    public function handle(User $user, string $campoErro = 'password'): void
    {
        DB::transaction(function () use ($user, $campoErro): void {
            $this->garantirAdministradorAtivo->handle($user, $campoErro);

            $user->forceFill(['ultima_carteira_id' => null])->save();

            $user->lancamentos()->delete();
            $user->cotas()->delete();
            $user->parcelamentos()->delete();
            $user->grupos()->delete();
            $user->carteiras()->delete();
            $user->categorias()->delete();

            $user->delete();
        });

        Storage::disk('local')->deleteDirectory("anexos/{$user->id}");
    }
}
