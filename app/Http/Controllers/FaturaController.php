<?php

namespace App\Http\Controllers;

use App\Actions\Cartoes\DistribuirPagamentosDoContato;
use App\Actions\Cartoes\EditarDatasFatura;
use App\Actions\Cartoes\Espelhos;
use App\Actions\Cartoes\PagarFatura;
use App\Http\Requests\Cartoes\EditarDatasFaturaRequest;
use App\Http\Requests\Cartoes\PagarFaturaRequest;
use App\Models\Carteira;
use App\Models\PagamentoFatura;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class FaturaController extends Controller
{
    /**
     * Pay the invoice due in the month, settling the marked contacts' parts too (RF-165, RF-185).
     */
    public function pagar(PagarFaturaRequest $request, Carteira $carteira, string $referencia, PagarFatura $pagarFatura): RedirectResponse
    {
        $pagarFatura->handle(
            $this->cartao($carteira),
            $this->referencia($referencia),
            (int) $request->validated('valor'),
            (string) $request->validated('data'),
            array_values(array_map(intval(...), $request->validated('contatos') ?? [])),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pagamento registrado.']);

        return back();
    }

    /**
     * Change closing and due date of an open or future invoice (RF-163).
     */
    public function atualizarDatas(EditarDatasFaturaRequest $request, Carteira $carteira, string $referencia, EditarDatasFatura $editarDatas): RedirectResponse
    {
        $editarDatas->handle(
            $this->cartao($carteira),
            $this->referencia($referencia),
            CarbonImmutable::parse((string) $request->validated('fechamento')),
            CarbonImmutable::parse((string) $request->validated('vencimento')),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Datas da fatura atualizadas.']);

        return back();
    }

    /**
     * Delete a payment; payments are never edited (RF-166, RN-222). Its settlements, "a receber" and
     * automatic receipts go with it (foreign keys), and the contacts' mirror payments of the card are
     * redistributed when the payment was a contact's or settled a part (RN-240, RN-241, RN-255). A mirror left without shares and payments is deleted
     * (RN-261 c).
     */
    public function excluirPagamento(PagamentoFatura $pagamentoFatura, Espelhos $espelhos, DistribuirPagamentosDoContato $distribuir): RedirectResponse
    {
        $cartao = $pagamentoFatura->fatura->carteira;
        Gate::authorize('update', $cartao);

        DB::transaction(function () use ($pagamentoFatura, $espelhos, $distribuir, $cartao): void {
            DistribuirPagamentosDoContato::travar($cartao);
            $redistribuir = $cartao->cartao()->value('cartao_origem_id') !== null || $pagamentoFatura->quitacoes()->exists();
            $pagamentoFatura->delete();

            if ($redistribuir) {
                $distribuir->doCartao($cartao);
            }
            $espelhos->excluirVazios([$cartao->id]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Pagamento excluído.']);

        return back();
    }

    private function cartao(Carteira $carteira): Carteira
    {
        abort_unless($carteira->isCartao(), 404);

        return $carteira;
    }

    private function referencia(string $referencia): CarbonImmutable
    {
        $mes = CarbonImmutable::createFromFormat('!Y-m', $referencia);
        abort_if($mes === null, 404);

        return $mes;
    }
}
