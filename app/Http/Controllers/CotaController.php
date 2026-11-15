<?php

namespace App\Http\Controllers;

use App\Http\Requests\Lancamentos\AlternarExecutadoRequest;
use App\Models\Cota;
use Illuminate\Http\RedirectResponse;

class CotaController extends Controller
{
    /**
     * Mark or unmark a share as executed (RF-042, RN-014, RN-095).
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
}
