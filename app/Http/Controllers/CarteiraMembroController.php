<?php

namespace App\Http\Controllers;

use App\Actions\Carteiras\ConvidarMembro;
use App\Enums\StatusMembro;
use App\Http\Requests\Membros\ConvidarMembroRequest;
use App\Models\Carteira;
use App\Models\CarteiraMembro;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class CarteiraMembroController extends Controller
{
    /**
     * Invite a contact to be a member of the wallet (RF-200, RN-287).
     */
    public function store(ConvidarMembroRequest $request, Carteira $carteira, ConvidarMembro $convidarMembro): RedirectResponse
    {
        $convidado = $request->convidado();

        $mensagem = $convidarMembro->handle($carteira, $convidado)
            ? 'Convite enviado.'
            : "{$convidado->name} já é membro ou tem convite pendente.";

        Inertia::flash('toast', ['type' => 'success', 'message' => $mensagem]);

        return to_route('carteiras.index');
    }

    /**
     * Accept a member invitation (RF-201).
     */
    public function aceitar(CarteiraMembro $membro): RedirectResponse
    {
        Gate::authorize('responder', $membro);

        $membro->update(['status' => StatusMembro::Aceito, 'respondido_em' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Convite aceito.']);

        return to_route('carteiras.index');
    }

    /**
     * Refuse a member invitation (RF-201).
     */
    public function recusar(CarteiraMembro $membro): RedirectResponse
    {
        Gate::authorize('responder', $membro);

        $membro->update(['status' => StatusMembro::Recusado, 'respondido_em' => now()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Convite recusado.']);

        return to_route('carteiras.index');
    }

    /**
     * The owner cancels a pending invitation or removes a member; the member leaves (RF-202).
     */
    public function destroy(Request $request, CarteiraMembro $membro): RedirectResponse
    {
        Gate::authorize('delete', $membro);

        /** @var User $user */
        $user = $request->user();

        $mensagem = match (true) {
            $membro->user_id === $user->id => "Você saiu de {$membro->carteira->nome}.",
            $membro->status === StatusMembro::Pendente => 'Convite cancelado.',
            default => 'Membro removido.',
        };

        $membro->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $mensagem]);

        return to_route('carteiras.index');
    }
}
