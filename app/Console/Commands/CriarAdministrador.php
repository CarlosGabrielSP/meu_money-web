<?php

namespace App\Console\Commands;

use App\Actions\Usuarios\CriarUsuario;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

#[Signature('usuario:admin {nome : Nome do administrador} {email : E-mail do administrador}')]
#[Description('Cria um usuário administrador e envia o link para definir a senha (RF-001)')]
class CriarAdministrador extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(CriarUsuario $criarUsuario): int
    {
        $validator = Validator::make(
            ['nome' => $this->argument('nome'), 'email' => $this->argument('email')],
            [
                'nome' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $erro) {
                $this->error($erro);
            }

            return self::FAILURE;
        }

        [$user, $linkEnviado] = $criarUsuario->handle($validator->validated()['nome'], $validator->validated()['email'], isAdmin: true);

        if (! $linkEnviado) {
            $this->warn("Administrador {$user->email} criado, mas o e-mail não foi enviado. Use \"Esqueci minha senha\" na tela de login.");

            return self::SUCCESS;
        }

        $this->info("Administrador {$user->email} criado. Link para definir a senha enviado por e-mail.");

        return self::SUCCESS;
    }
}
