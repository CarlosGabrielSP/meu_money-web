<?php

namespace App\Http\Controllers;

use App\Actions\Carteiras\DefinirCarteiraPadrao;
use App\Actions\Carteiras\ExcluirCarteira;
use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Http\Requests\Carteiras\ExcluirCarteiraRequest;
use App\Http\Requests\Carteiras\SalvarCarteiraRequest;
use App\Models\Carteira;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CarteiraController extends Controller
{
    /**
     * List the user's own wallets (RF-020).
     */
    public function index(Request $request): Response
    {
        return Inertia::render('carteiras/Index', [
            'carteiras' => $request->user()->carteiras()
                ->orderByDesc('padrao')
                ->orderBy('nome')
                ->withCount(['cotas', 'parcelamentos', 'grupos'])
                ->get(['id', 'nome', 'padrao', 'cor', 'icone'])
                ->map(fn (Carteira $carteira): array => [
                    'id' => $carteira->id,
                    'nome' => $carteira->nome,
                    'padrao' => $carteira->padrao,
                    'cor' => $carteira->cor->value,
                    'icone' => $carteira->icone->value,
                    'temConteudo' => $carteira->cotas_count + $carteira->parcelamentos_count + $carteira->grupos_count > 0,
                ]),
            'cores' => array_column(CorCarteira::cases(), 'value'),
            'icones' => array_column(IconeCarteira::cases(), 'value'),
        ]);
    }

    /**
     * Create a wallet, never as default (RF-021).
     */
    public function store(SalvarCarteiraRequest $request): RedirectResponse
    {
        $request->user()->carteiras()->create([...$request->dados(), 'padrao' => false]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Carteira criada.']);

        return to_route('carteiras.index');
    }

    /**
     * Rename or change color and icon (RF-022).
     */
    public function update(SalvarCarteiraRequest $request, Carteira $carteira): RedirectResponse
    {
        $carteira->update($request->dados());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Carteira atualizada.']);

        return to_route('carteiras.index');
    }

    /**
     * Make the wallet the default one (RF-023).
     */
    public function definirPadrao(Carteira $carteira, DefinirCarteiraPadrao $definirCarteiraPadrao): RedirectResponse
    {
        Gate::authorize('update', $carteira);

        $definirCarteiraPadrao->handle($carteira);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$carteira->nome} agora é a carteira padrão."]);

        return to_route('carteiras.index');
    }

    /**
     * Delete the wallet, moving or deleting its contents (RF-024).
     */
    public function destroy(ExcluirCarteiraRequest $request, Carteira $carteira, ExcluirCarteira $excluirCarteira): RedirectResponse
    {
        $excluirCarteira->handle($carteira, $request->destino());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Carteira excluída.']);

        return to_route('carteiras.index');
    }
}
