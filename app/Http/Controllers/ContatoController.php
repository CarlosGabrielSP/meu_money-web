<?php

namespace App\Http\Controllers;

use App\Actions\Contatos\EnviarConvite;
use App\Actions\Contatos\ResponderConvite;
use App\Enums\StatusContato;
use App\Http\Requests\Contatos\ConvidarContatoRequest;
use App\Models\Contato;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

class ContatoController extends Controller
{
    private const int CONVITES_POR_HORA = 20;

    /**
     * Contacts, received invitations and sent pending invitations (RF-062, RF-063).
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $registros = Contato::query()
            ->envolvendo($user)
            ->whereIn('status', [StatusContato::Aceito, StatusContato::Pendente])
            ->with(['solicitante:id,name,email,desativado_em', 'convidado:id,name,email,desativado_em'])
            ->latest()
            ->get()
            ->filter(fn (Contato $contato): bool => $contato->outroLado($user)->desativado_em === null);

        $linha = function (Contato $contato) use ($user): array {
            $outro = $contato->outroLado($user);

            return ['id' => $contato->id, 'nome' => $outro->name, 'email' => $outro->email];
        };

        return Inertia::render('contatos/Index', [
            'contatos' => $registros->where('status', StatusContato::Aceito)
                ->map($linha)->sortBy('nome', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'recebidos' => $registros->where('status', StatusContato::Pendente)
                ->where('convidado_id', $user->id)->map($linha)->values(),
            'enviados' => $registros->where('status', StatusContato::Pendente)
                ->where('solicitante_id', $user->id)->map($linha)->values(),
        ]);
    }

    /**
     * Send an invitation; the answer is the same whatever the email (RF-060, RN-065).
     * Only valid invitations count toward the limit of 20 per hour (RNF-024, DEC-049).
     */
    public function store(ConvidarContatoRequest $request, EnviarConvite $enviarConvite): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $chave = "convites:{$user->id}";

        abort_if(RateLimiter::tooManyAttempts($chave, self::CONVITES_POR_HORA), 429);

        RateLimiter::hit($chave, 3600);

        $enviarConvite->handle($user, $request->string('email')->toString());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Se o e-mail estiver cadastrado, o convite foi enviado.']);

        return to_route('contatos.index');
    }

    /**
     * Accept a pending invitation (RF-061). Invitations from deactivated users cannot be accepted (RN-109).
     */
    public function aceitar(Contato $contato, ResponderConvite $responderConvite): RedirectResponse
    {
        Gate::authorize('responder', $contato);

        $responderConvite->handle($contato, aceitar: true);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Convite aceito.']);

        return to_route('contatos.index');
    }

    /**
     * Refuse a pending invitation (RF-061).
     */
    public function recusar(Contato $contato, ResponderConvite $responderConvite): RedirectResponse
    {
        Gate::authorize('responder', $contato);

        $responderConvite->handle($contato, aceitar: false);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Convite recusado.']);

        return to_route('contatos.index');
    }

    /**
     * Cancel a sent invitation (RN-068) or remove a contact, keeping existing shares (RN-063, RN-064).
     */
    public function destroy(Contato $contato): RedirectResponse
    {
        Gate::authorize('delete', $contato);

        $pendente = $contato->status === StatusContato::Pendente;

        $contato->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $pendente ? 'Convite cancelado.' : 'Contato removido.']);

        return to_route('contatos.index');
    }
}
