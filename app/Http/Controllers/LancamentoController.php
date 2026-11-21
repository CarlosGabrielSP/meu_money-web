<?php

namespace App\Http\Controllers;

use App\Actions\Lancamentos\ExcluirLancamento;
use App\Actions\Lancamentos\RegistrarQuemAgiu;
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
    public function __construct(private RegistrarQuemAgiu $registrarQuemAgiu) {}

    /**
     * Create a simple entry or a series (RF-040, RF-050, RF-051).
     *
     * A series starts as its first installment, then the others are generated; series have no status
     * choice in the form (RN-013). A member creates it for the owner of the delegated wallet, who is
     * the author; every installment created records the member (RN-273, RN-293, CA-205.7).
     */
    public function store(SalvarLancamentoRequest $request, SalvarLancamento $salvarLancamento, CriarSerie $criarSerie): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $dono = $request->dono();
        $dados = $request->dados();
        $repeticao = $request->repeticao();

        $lancamento = $salvarLancamento->handle(
            $dono,
            $repeticao === null ? $dados : [...$dados, 'executado' => null],
            $request->anexo(),
            null,
            $repeticao === null ? null : function (Lancamento $primeira) use ($criarSerie, $dono, $dados, $repeticao): void {
                $criarSerie->handle($dono, $dados, $repeticao, $primeira);
            },
        );

        $this->registrarQuemAgiu->criou($user, $dono, $this->daSerie($lancamento));

        Inertia::flash('toast', ['type' => 'success', 'message' => $repeticao === null ? 'Lançamento criado.' : 'Série criada.']);

        return back();
    }

    /**
     * Update a simple entry, convert it into a series, or edit installments with a scope
     * (RF-041, RF-053, RF-057). A member acts for the author and reaches only installments of the
     * wallets delegated to them (RN-278, RN-291); the changed entries record who acted (RN-293).
     */
    public function update(SalvarLancamentoRequest $request, Lancamento $lancamento, SalvarLancamento $salvarLancamento, CriarSerie $criarSerie, EditarParcelas $editarParcelas): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $dono = $request->dono();
        $dados = $request->dados();

        if ($request->parcela()) {
            /** @var EscopoSerie $escopo */
            $escopo = $request->escopo();

            $alteradas = $editarParcelas->handle($dono, $lancamento, $escopo, $dados, $request->diaVencimento(), $request->anexo(), $user->carteirasDelegadasPor($dono));
            $this->registrarQuemAgiu->alterou($user, $dono, $alteradas);

            Inertia::flash('toast', ['type' => 'success', 'message' => 'Parcelas atualizadas.']);

            return back();
        }

        $repeticao = $request->repeticao();

        $salvarLancamento->handle(
            $dono,
            $dados,
            $request->anexo(),
            $lancamento,
            $repeticao === null ? null : function (Lancamento $primeira) use ($criarSerie, $dono, $dados, $repeticao): void {
                $criarSerie->handle($dono, $dados, $repeticao, $primeira);
            },
        );

        $this->registrarQuemAgiu->alterou($user, $dono, [$lancamento->id]);
        $this->registrarQuemAgiu->criou($user, $dono, array_values(array_diff($this->daSerie($lancamento), [$lancamento->id])));

        Inertia::flash('toast', ['type' => 'success', 'message' => $repeticao === null ? 'Lançamento atualizado.' : 'Lançamento convertido em série.']);

        return back();
    }

    /**
     * Delete a simple entry, or installments with a scope (RF-043, RF-054). A member removes only
     * installments of the wallets delegated to them (RN-278, RN-291).
     */
    public function destroy(ExcluirLancamentoRequest $request, Lancamento $lancamento, ExcluirLancamento $excluirLancamento, ExcluirParcelas $excluirParcelas): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $escopo = $request->escopo();

        if ($lancamento->parcelamento_id !== null && $escopo !== null) {
            $excluirParcelas->handle($lancamento, $escopo, $user->carteirasDelegadasPor($lancamento->user));
        } else {
            $excluirLancamento->handle($lancamento);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Lançamento excluído.']);

        return back();
    }

    /**
     * The entry, or every installment of its series once it is one.
     *
     * @return list<int>
     */
    private function daSerie(Lancamento $lancamento): array
    {
        $serieId = $lancamento->refresh()->parcelamento_id;

        return $serieId === null
            ? [$lancamento->id]
            : array_values(array_map(intval(...), Lancamento::query()->where('parcelamento_id', $serieId)->pluck('id')->all()));
    }
}
