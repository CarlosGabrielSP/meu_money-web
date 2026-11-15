<?php

namespace App\Actions\Usuarios;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class DesativarUsuario
{
    public function __construct(private GarantirAdministradorAtivo $garantirAdministradorAtivo) {}

    /**
     * Block the user's login and end their active sessions, keeping their data (RN-108, RN-111).
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $this->garantirAdministradorAtivo->handle($user);

            $user->forceFill(['desativado_em' => now()])->save();

            DB::table(config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        });
    }
}
