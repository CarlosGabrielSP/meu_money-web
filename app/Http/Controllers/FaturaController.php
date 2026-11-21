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
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class FaturaController extends Controller
{
    /**
     * Pay the invoice due in the month, settling the marked contacts' parts too (RF-165, RF-185). A
     * member of the linked wallet pays as the owner and is stored as who paid (RN-281, CA-208.2).
     */
    public function pagar(PagarFaturaRequest $request, Carteira $carteira, string $referencia, PagarFatura $pagarFatura): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $pagarFatura->handle(
            $this->cartao($carteira),
            $this->referencia($referencia),
            (int) $request->validated('valor'),
            (string) $request->validated('data'),
            array_values(array_map(intval(...), $request->validated('contatos') ?? [])),
            $carteira->user_id === $user->id ? null : $user,
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
     * (RN-261 c). A member deletes a payment that left a wallet delegated to them, of an invoice linked
     * to a delegated wallet, with the same effects (RN-281, RN-304, CA-208.3, CA-208.7); a member of
     * the card deletes any payment of its invoices (RN-317, CA-214.4).
     */
    public function excluirPagamento(Request $request, PagamentoFatura $pagamentoFatura, Espelhos $espelhos, DistribuirPagamentosDoContato $distribuir): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $cartao = $pagamentoFatura->fatura->carteira;
        $doMembro = $cartao->isDelegadaA($user) || ($pagamentoFatura->carteira_id !== null
            && $pagamentoFatura->fatura->isVinculadaADelegadaDe($user)
            && $user->carteirasDelegadas()->whereKey($pagamentoFatura->carteira_id)->exists());
        abort_unless($doMembro || $user->can('update', $cartao), 403);

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
