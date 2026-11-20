<?php

namespace App\Http\Controllers;

use App\Http\Requests\Cartoes\AlternarRecebidoRequest;
use App\Models\QuitacaoContato;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class AReceberController extends Controller
{
    /**
     * Mark or unmark an "a receber" item as received (RF-186, RN-242): marking receives the rest on
     * today's date as a manual receipt; unmarking deletes only the manual receipts, so the automatic
     * ones from the contact's mirror payments stay. Received never goes above the total.
     */
    public function alternar(AlternarRecebidoRequest $request, QuitacaoContato $quitacaoContato): RedirectResponse
    {
        DB::transaction(function () use ($request, $quitacaoContato): void {
            $quitacao = QuitacaoContato::query()->whereKey($quitacaoContato->id)->lockForUpdate()->firstOrFail();

            if (! $request->boolean('recebido')) {
                $quitacao->recebimentos()->whereNull('pagamento_fatura_id')->delete();

                return;
            }

            $restante = $quitacao->valor - (int) $quitacao->recebimentos()->sum('valor');

            if ($restante > 0) {
                $quitacao->recebimentos()->create(['valor' => $restante, 'data' => CarbonImmutable::today()->toDateString()]);
            }
        });

        return back();
    }
}
