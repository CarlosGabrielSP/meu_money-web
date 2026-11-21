<?php

namespace App\Http\Controllers;

use App\Actions\Lancamentos\RegistrarQuemAgiu;
use App\Actions\Lancamentos\RemoverAnexo;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LancamentoAnexoController extends Controller
{
    /**
     * Download the attachment from the private disk (RN-017, RN-018, CA-044.4).
     */
    public function show(Lancamento $lancamento): StreamedResponse
    {
        Gate::authorize('baixarAnexo', $lancamento);

        abort_if($lancamento->anexo_caminho === null || ! Storage::disk('local')->exists($lancamento->anexo_caminho), 404);

        return Storage::disk('local')->download($lancamento->anexo_caminho, $lancamento->anexo_nome);
    }

    /**
     * Remove the attachment (CA-044.5, CA-044.6); a member's removal records who acted (RN-293).
     */
    public function destroy(Request $request, Lancamento $lancamento, RemoverAnexo $removerAnexo, RegistrarQuemAgiu $registrarQuemAgiu): RedirectResponse
    {
        Gate::authorize('update', $lancamento);

        /** @var User $user */
        $user = $request->user();

        $removerAnexo->handle($lancamento);
        $registrarQuemAgiu->alterou($user, $lancamento->user, [$lancamento->id]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Anexo removido.']);

        return back();
    }
}
