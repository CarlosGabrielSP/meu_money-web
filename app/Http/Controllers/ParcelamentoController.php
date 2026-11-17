<?php

namespace App\Http\Controllers;

use App\Actions\Series\AlterarTotalParcelas;
use App\Actions\Series\EncerrarRecorrente;
use App\Actions\Series\RastrearSerie;
use App\Http\Requests\Series\AlterarTotalParcelasRequest;
use App\Http\Requests\Series\EncerrarSerieRequest;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class ParcelamentoController extends Controller
{
    /**
     * Read-only tracking of a series with the user's installments (RF-150, RF-151).
     */
    public function show(Request $request, Parcelamento $parcelamento, RastrearSerie $rastrearSerie): Response
    {
        Gate::authorize('view', $parcelamento);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('parcelamentos/Show', [
            ...$rastrearSerie->handle($user, $parcelamento, CarbonImmutable::today()),
            'voltarPara' => $this->paginaAnterior($request),
        ]);
    }

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

    /**
     * Page of the app the user came from, taken from the Referer header (Inertia visits do not
     * update the previous URL of the session). A link pasted in the browser or coming from another
     * site goes back to the home screen (note 07).
     */
    private function paginaAnterior(Request $request): string
    {
        $origem = $request->headers->get('referer');
        $app = parse_url(url('/'));

        if (
            $origem === null
            || parse_url($origem, PHP_URL_HOST) !== ($app['host'] ?? null)
            || parse_url($origem, PHP_URL_PORT) !== ($app['port'] ?? null)
            || $origem === $request->fullUrl()
        ) {
            return route('home');
        }

        return $origem;
    }
}
