<?php

namespace App\Http\Controllers;

use App\Actions\Lancamentos\ExcluirLancamento;
use App\Actions\Lancamentos\SalvarLancamento;
use App\Http\Requests\Lancamentos\SalvarLancamentoRequest;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class LancamentoController extends Controller
{
    /**
     * Create a simple entry with the author's share (RF-040).
     */
    public function store(SalvarLancamentoRequest $request, SalvarLancamento $salvarLancamento): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $salvarLancamento->handle($user, $request->dados(), $request->anexo());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento criado.']);

        return back();
    }

    /**
     * Update a simple entry and its share (RF-041).
     */
    public function update(SalvarLancamentoRequest $request, Lancamento $lancamento, SalvarLancamento $salvarLancamento): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $salvarLancamento->handle($user, $request->dados(), $request->anexo(), $lancamento);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento atualizado.']);

        return back();
    }

    /**
     * Delete a simple entry (RF-043).
     */
    public function destroy(Lancamento $lancamento, ExcluirLancamento $excluirLancamento): RedirectResponse
    {
        Gate::authorize('delete', $lancamento);

        $excluirLancamento->handle($lancamento);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento excluído.']);

        return back();
    }
}
