<?php

namespace Database\Seeders;

use App\Actions\Usuarios\CriarUsuario;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(CriarUsuario $criarUsuario): void
    {
        $this->call(CategoriasGlobaisSeeder::class);

        $this->criarAdministrador($criarUsuario);
    }

    /**
     * Local administrator from `SEED_ADMIN_NAME` and `SEED_ADMIN_EMAIL`, created like `usuario:admin`:
     * default wallet and the set-password link by email (RF-001, RN-002). Skipped when either is empty
     * or the email is already registered.
     */
    private function criarAdministrador(CriarUsuario $criarUsuario): void
    {
        $nome = config('app.seed_admin.name');
        $email = config('app.seed_admin.email');

        if (! is_string($nome) || $nome === '' || ! is_string($email) || $email === '') {
            return;
        }

        if (User::query()->where('email', $email)->exists()) {
            return;
        }

        $criarUsuario->handle($nome, $email, isAdmin: true);
    }
}
