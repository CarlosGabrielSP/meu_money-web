<?php

namespace App\Http\Controllers;

use App\Actions\Categorias\ExcluirCategoria;
use App\Http\Requests\Categorias\SalvarCategoriaRequest;
use App\Models\Categoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CategoriaController extends Controller
{
    /**
     * List system categories and the user's own, grouped by type on the page (RF-030).
     */
    public function index(Request $request): Response
    {
        return Inertia::render('categorias/Index', [
            'categorias' => Categoria::query()
                ->visiveisPara($request->user())
                ->orderBy('nome')
                ->get(['id', 'user_id', 'nome', 'tipo'])
                ->map(fn (Categoria $categoria): array => [
                    'id' => $categoria->id,
                    'nome' => $categoria->nome,
                    'tipo' => $categoria->tipo->value,
                    'doSistema' => $categoria->isGlobal(),
                ]),
        ]);
    }

    /**
     * Create a category owned by the user (RF-031).
     */
    public function store(SalvarCategoriaRequest $request): RedirectResponse
    {
        $request->user()->categorias()->create($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria criada.']);

        return to_route('categorias.index');
    }

    /**
     * Rename or retype a user category (RF-032).
     */
    public function update(SalvarCategoriaRequest $request, Categoria $categoria): RedirectResponse
    {
        $categoria->update($request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria atualizada.']);

        return to_route('categorias.index');
    }

    /**
     * Delete a user category, moving its uses to "Outros" (RF-033).
     */
    public function destroy(Categoria $categoria, ExcluirCategoria $excluirCategoria): RedirectResponse
    {
        Gate::authorize('delete', $categoria);

        $excluirCategoria->handle($categoria);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Categoria excluída.']);

        return to_route('categorias.index');
    }
}
