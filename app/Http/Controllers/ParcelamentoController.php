<?php

namespace App\Http\Controllers;

use App\Actions\Series\AlterarTotalParcelas;
use App\Actions\Series\EncerrarRecorrente;
use App\Http\Requests\Series\AlterarTotalParcelasRequest;
use App\Http\Requests\Series\EncerrarSerieRequest;
use App\Models\Parcelamento;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class ParcelamentoController extends Controller
{
    /**
     * End a recurring series (RF-055).
     */
    public function encerrar(EncerrarSerieRequest $request, Parcelamento $parcelamento, EncerrarRecorrente $encerrarRecorrente): RedirectResponse
    {
        $encerrarRecorrente->handle($parcelamento, $request->dataEncerramento());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Série encerrada.']);

        return back();
    }

    /**
     * Change the number of installments (RF-056).
     */
    public function alterarTotal(AlterarTotalParcelasRequest $request, Parcelamento $parcelamento, AlterarTotalParcelas $alterarTotalParcelas): RedirectResponse
    {
        $alterarTotalParcelas->handle($parcelamento, $request->total());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Total de parcelas alterado.']);

        return back();
    }
}
