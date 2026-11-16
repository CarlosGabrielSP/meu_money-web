<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Usuarios\CriarUsuario;
use App\Actions\Usuarios\DesativarUsuario;
use App\Actions\Usuarios\EnviarLinkDefinirSenha;
use App\Actions\Usuarios\GarantirAdministradorAtivo;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AlterarDataNascimentoRequest;
use App\Http\Requests\Admin\AlterarEmailRequest;
use App\Http\Requests\Admin\CriarUsuarioRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class UsuarioController extends Controller
{
    /**
     * List accounts without any financial data (RF-006, RN-110).
     */
    public function index(): Response
    {
        return Inertia::render('admin/Usuarios', [
            'usuarios' => User::query()
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'is_admin', 'desativado_em', 'password'])
                ->map(fn (User $user): array => [
                    'id' => $user->id,
                    'nome' => $user->name,
                    'email' => $user->email,
                    'isAdmin' => $user->is_admin,
                    'ativo' => $user->desativado_em === null,
                    'temSenha' => $user->password !== null,
                ]),
        ]);
    }

    /**
     * Create an account and email the set-password link (RF-002).
     */
    public function store(CriarUsuarioRequest $request, CriarUsuario $criarUsuario): RedirectResponse
    {
        [, $linkEnviado] = $criarUsuario->handle(
            $request->validated('nome'),
            $request->validated('email'),
            dataNascimento: $request->validated('data_nascimento'),
        );

        Inertia::flash('toast', $linkEnviado
            ? ['type' => 'success', 'message' => 'Usuário criado. Enviamos o link para definir a senha.']
            : ['type' => 'warning', 'message' => 'Usuário criado, mas o e-mail não foi enviado. Use "Reenviar link".']);

        return to_route('admin.usuarios.index');
    }

    /**
     * Deactivate an account and end its sessions (RF-006, CA-006.2).
     */
    public function desativar(User $usuario, DesativarUsuario $desativarUsuario): RedirectResponse
    {
        $desativarUsuario->handle($usuario);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Usuário desativado.']);

        return to_route('admin.usuarios.index');
    }

    /**
     * Allow the account to log in again (CA-006.3).
     */
    public function reativar(User $usuario): RedirectResponse
    {
        $usuario->forceFill(['desativado_em' => null])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Usuário reativado.']);

        return to_route('admin.usuarios.index');
    }

    /**
     * Grant or revoke the administrator flag (CA-006.4, CA-006.5).
     */
    public function alterarAdministrador(Request $request, User $usuario, GarantirAdministradorAtivo $garantirAdministradorAtivo): RedirectResponse
    {
        $isAdmin = (bool) $request->validate(['is_admin' => ['required', 'boolean']])['is_admin'];

        DB::transaction(function () use ($usuario, $isAdmin, $garantirAdministradorAtivo): void {
            if (! $isAdmin) {
                $garantirAdministradorAtivo->handle($usuario);
            }

            $usuario->forceFill(['is_admin' => $isAdmin])->save();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Permissão de administrador atualizada.']);

        return to_route('admin.usuarios.index');
    }

    /**
     * Resend the set-password link to an account that has no password yet (CA-006.6).
     */
    public function reenviarLink(User $usuario, EnviarLinkDefinirSenha $enviarLinkDefinirSenha): RedirectResponse
    {
        abort_if($usuario->password !== null, 422, 'Usuário já definiu a senha.');

        Inertia::flash('toast', $enviarLinkDefinirSenha->handle($usuario)
            ? ['type' => 'success', 'message' => 'Link para definir a senha reenviado.']
            : ['type' => 'error', 'message' => 'Não foi possível enviar o e-mail. Tente novamente.']);

        return to_route('admin.usuarios.index');
    }

    /**
     * Change a user's email; the new address must be confirmed again (RN-134, CA-006.7).
     */
    public function alterarEmail(AlterarEmailRequest $request, User $usuario): RedirectResponse
    {
        $usuario->forceFill([
            'email' => $request->validated('email'),
            'email_verified_at' => null,
        ])->save();

        try {
            $usuario->sendEmailVerificationNotification();
            Inertia::flash('toast', ['type' => 'success', 'message' => 'E-mail alterado. Enviamos o link de confirmação.']);
        } catch (Throwable $exception) {
            report($exception);
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'E-mail alterado, mas o link de confirmação não foi enviado.']);
        }

        return to_route('admin.usuarios.index');
    }

    /**
     * Set or remove a user's birth date; an empty value removes it (RN-154, CA-110.3).
     */
    public function alterarDataNascimento(AlterarDataNascimentoRequest $request, User $usuario): RedirectResponse
    {
        $usuario->forceFill(['data_nascimento' => $request->validated('data_nascimento')])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Data de nascimento atualizada.']);

        return to_route('admin.usuarios.index');
    }
}
