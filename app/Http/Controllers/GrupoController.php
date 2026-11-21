<?php

namespace App\Http\Controllers;

use App\Actions\Grupos\AgruparCotas;
use App\Actions\Grupos\DesagruparCotas;
use App\Actions\Lancamentos\RegistrarQuemAgiu;
use App\Http\Requests\Grupos\AgruparRequest;
use App\Http\Requests\Grupos\DesagruparRequest;
use App\Http\Requests\Grupos\ExecutarGrupoRequest;
use App\Http\Requests\Grupos\RenomearGrupoRequest;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class GrupoController extends Controller
{
    /**
     * Group the selected shares (RF-090). A member groups the owner's shares and a new group is the
     * owner's (RN-292, CA-204.7).
     */
    public function agrupar(AgruparRequest $request, AgruparCotas $agruparCotas): RedirectResponse
    {
        $agruparCotas->handle($request->dono(), $request->cotas(), $request->grupo() ?? (string) $request->validated('nome'), $request->escopo());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Itens agrupados.']);

        return back();
    }

    /**
     * Take the selected shares out of their groups (RF-093, CA-093.1).
     */
    public function desagrupar(DesagruparRequest $request, DesagruparCotas $desagruparCotas): RedirectResponse
    {
        $desagruparCotas->handle($request->dono(), $request->cotas(), $request->escopo());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Itens desagrupados.']);

        return back();
    }

    /**
     * Mark or unmark every visible share of the group in the month (RF-092, RN-014, RN-128). The
     * changed entries record who acted (RN-293, CA-205.8).
     */
    public function executar(ExecutarGrupoRequest $request, Grupo $grupo, RegistrarQuemAgiu $registrarQuemAgiu): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $executado = $request->boolean('executado');
        DB::transaction(function () use ($request, $executado, $registrarQuemAgiu, $user, $grupo): void {
            $alteradas = Cota::query()->whereKey($request->cotas())->where('executado', ! $executado);
            $lancamentos = array_values(array_map(intval(...), (clone $alteradas)->pluck('lancamento_id')->all()));

            $alteradas->update(['executado' => $executado, 'executado_em' => $executado ? now() : null]);
            $registrarQuemAgiu->alterou($user, $grupo->user, $lancamentos);
        });

        return back();
    }

    /**
     * Rename a group; the name changes in every month (CA-093.2).
     */
    public function update(RenomearGrupoRequest $request, Grupo $grupo): RedirectResponse
    {
        $grupo->update(['nome' => $request->validated('nome')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grupo renomeado.']);

        return back();
    }

    /**
     * Delete a group: only the grouping is undone, shares and balances stay (RN-131, CA-093.3). The
     * foreign keys of shares, series and model shares are null on delete (data model 05).
     */
    public function destroy(Grupo $grupo): RedirectResponse
    {
        Gate::authorize('update', $grupo);

        $grupo->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grupo excluído.']);

        return back();
    }
}
