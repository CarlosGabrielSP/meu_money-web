<?php

namespace App\Http\Controllers;

use App\Actions\Lancamentos\ExcluirLancamento;
use App\Actions\Lancamentos\SalvarLancamento;
use App\Actions\Series\CriarSerie;
use App\Actions\Series\EditarParcelas;
use App\Actions\Series\ExcluirParcelas;
use App\Enums\EscopoSerie;
use App\Http\Requests\Lancamentos\ExcluirLancamentoRequest;
use App\Http\Requests\Lancamentos\SalvarLancamentoRequest;
use App\Models\Lancamento;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class LancamentoController extends Controller
{
    /**
     * Create a simple entry or a series (RF-040, RF-050, RF-051).
     *
     * A series starts as its first installment, then the others are generated; series have no status
     * choice in the form (RN-013).
     */
    public function store(SalvarLancamentoRequest $request, SalvarLancamento $salvarLancamento, CriarSerie $criarSerie): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $dados = $request->dados();
        $repeticao = $request->repeticao();

        $salvarLancamento->handle(
            $user,
            $repeticao === null ? $dados : [...$dados, 'executado' => null],
            $request->anexo(),
            null,
            $repeticao === null ? null : function (Lancamento $primeira) use ($criarSerie, $user, $dados, $repeticao): void {
                $criarSerie->handle($user, $dados, $repeticao, $primeira);
            },
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $repeticao === null ? 'Lançamento criado.' : 'Série criada.']);

        return back();
    }

    /**
     * Update a simple entry, convert it into a series, or edit installments with a scope
     * (RF-041, RF-053, RF-057).
     */
    public function update(SalvarLancamentoRequest $request, Lancamento $lancamento, SalvarLancamento $salvarLancamento, CriarSerie $criarSerie, EditarParcelas $editarParcelas): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $dados = $request->dados();

        if ($request->parcela()) {
            /** @var EscopoSerie $escopo */
            $escopo = $request->escopo();

            $editarParcelas->handle($user, $lancamento, $escopo, $dados, $request->diaVencimento(), $request->anexo());

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Parcelas atualizadas.']);

            return back();
        }

        $repeticao = $request->repeticao();

        $salvarLancamento->handle(
            $user,
            $dados,
            $request->anexo(),
            $lancamento,
            $repeticao === null ? null : function (Lancamento $primeira) use ($criarSerie, $user, $dados, $repeticao): void {
                $criarSerie->handle($user, $dados, $repeticao, $primeira);
            },
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => $repeticao === null ? 'Lançamento atualizado.' : 'Lançamento convertido em série.']);

        return back();
    }

    /**
     * Delete a simple entry, or installments with a scope (RF-043, RF-054).
     */
    public function destroy(ExcluirLancamentoRequest $request, Lancamento $lancamento, ExcluirLancamento $excluirLancamento, ExcluirParcelas $excluirParcelas): RedirectResponse
    {
        $escopo = $request->escopo();

        if ($lancamento->parcelamento_id !== null && $escopo !== null) {
            $excluirParcelas->handle($lancamento, $escopo);
        } else {
            $excluirLancamento->handle($lancamento);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento excluído.']);

        return back();
    }
}
