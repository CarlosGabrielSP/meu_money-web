<?php

namespace App\Http\Controllers;

use App\Actions\Rateio\SairDoRateio;
use App\Http\Requests\Lancamentos\AlternarExecutadoRequest;
use App\Http\Requests\Rateio\AtualizarCotaRequest;
use App\Http\Requests\Rateio\SairDoRateioRequest;
use App\Models\Cota;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class CotaController extends Controller
{
    /**
     * Mark or unmark a share as executed (RF-042, RN-014, RN-078, RN-095).
     */
    public function alternarExecutado(AlternarExecutadoRequest $request, Cota $cota): RedirectResponse
    {
        $executado = $request->boolean('executado');

        if ($executado !== $cota->executado) {
            $cota->forceFill([
                'executado' => $executado,
                'executado_em' => $executado ? now() : null,
            ])->save();
        }

        return back();
    }

    /**
     * A participant changes wallet, category and status of their own share (RF-071, RN-080). A share
     * moved to another wallet leaves its group (RN-130).
     */
    public function update(AtualizarCotaRequest $request, Cota $cota): RedirectResponse
    {
        $executado = $request->boolean('executado');
        $carteiraId = (int) $request->validated('carteira_id');

        $cota->forceFill([
            'grupo_id' => $carteiraId === $cota->carteira_id ? $cota->grupo_id : null,
            'carteira_id' => $carteiraId,
            'categoria_id' => (int) $request->validated('categoria_id'),
            'executado' => $executado,
            'executado_em' => $executado ? ($cota->executado ? $cota->executado_em : now()) : null,
        ])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cota atualizada.']);

        return back();
    }

    /**
     * A participant leaves the split (RF-073).
     */
    public function destroy(SairDoRateioRequest $request, Cota $cota, SairDoRateio $sairDoRateio): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $sairDoRateio->handle($user, $cota->lancamento, $request->escopo());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Você saiu da divisão.']);

        return back();
    }
}
