<?php

namespace App\Http\Controllers;

use App\Models\Cota;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RecebidaController extends Controller
{
    /**
     * Shares received from other authors, newest first, 30 per page (RF-071, RN-114, RN-144).
     * Opening a page marks the shares on it as seen; they are still highlighted on this visit.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $recebidas = Cota::query()
            ->recebidasPor($user)
            ->with(['lancamento:id,user_id,descricao,tipo,valor,data', 'lancamento.user:id,name', 'carteira:id,nome', 'categoria:id,nome'])
            ->latest('id')
            ->paginate(30);

        $vistas = $recebidas->getCollection()->whereNull('vista_em')->modelKeys();

        $recebidas->through(fn (Cota $cota): array => [
            'id' => $cota->id,
            'nova' => $cota->vista_em === null,
            'descricao' => $cota->lancamento->descricao,
            'tipo' => $cota->lancamento->tipo->value,
            'data' => $cota->lancamento->data->toDateString(),
            'autor' => $cota->lancamento->user->name,
            'valor' => $cota->valor,
            'total' => $cota->lancamento->valor,
            'carteira' => $cota->carteira->nome,
            'categoria' => $cota->categoria->nome,
        ]);

        Cota::query()->whereKey($vistas)->update(['vista_em' => now()]);

        return Inertia::render('recebidas/Index', ['recebidas' => $recebidas]);
    }
}
