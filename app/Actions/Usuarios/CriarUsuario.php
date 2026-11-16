<?php

namespace App\Actions\Usuarios;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class CriarUsuario
{
    public function __construct(private EnviarLinkDefinirSenha $enviarLinkDefinirSenha) {}

    /**
     * Create a user without password, with the default wallet, and email the set-password link
     * (RN-002, RN-100, RN-102). The user is kept even if the email fails (RN-135). The birth date is
     * optional (RN-154).
     *
     * @return array{0: User, 1: bool} the user and whether the link was sent
     */
    public function handle(string $nome, string $email, bool $isAdmin = false, ?string $dataNascimento = null): array
    {
        $user = DB::transaction(function () use ($nome, $email, $isAdmin, $dataNascimento): User {
            $user = new User(['name' => $nome, 'email' => $email, 'data_nascimento' => $dataNascimento]);
            $user->is_admin = $isAdmin;
            $user->save();

            $user->carteiras()->create([
                'nome' => 'Carteira principal',
                'padrao' => true,
            ]);

            return $user;
        });

        return [$user, $this->enviarLinkDefinirSenha->handle($user)];
    }
}
