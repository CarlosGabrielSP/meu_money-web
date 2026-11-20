<?php

namespace App\Http\Controllers;

use App\Actions\Carteiras\DefinirCarteiraPadrao;
use App\Actions\Carteiras\ExcluirCarteira;
use App\Actions\Cartoes\SalvarCartao;
use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Http\Requests\Carteiras\ExcluirCarteiraRequest;
use App\Http\Requests\Carteiras\SalvarCarteiraRequest;
use App\Models\Cartao;
use App\Models\Carteira;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class CarteiraController extends Controller
{
    /**
     * List the user's own wallets (RF-020), cards after the common ones, each card with its due day
     * and the wallet that pays it (RF-160, nota 07). A mirror carries the author's name (RF-182); an
     * author's card, the contacts with a mirror of it, for the delete dialog (nota 07, DEC-092).
     */
    public function index(Request $request): Response
    {
        return Inertia::render('carteiras/Index', [
            'carteiras' => $request->user()->carteiras()
                ->orderByDesc('padrao')
                ->orderBy('tipo', 'desc')
                ->orderBy('nome')
                ->withCount(['cotas', 'parcelamentos', 'grupos'])
                ->with(['cartao.carteiraVinculada:id,nome', 'cartao.cartaoOrigem.user:id,name', 'espelhos.carteira.user:id,name'])
                ->get(['id', 'nome', 'tipo', 'padrao', 'cor', 'icone'])
                ->map(fn (Carteira $carteira): array => [
                    'id' => $carteira->id,
                    'nome' => $carteira->nome,
                    'tipo' => $carteira->tipo->value,
                    'padrao' => $carteira->padrao,
                    'cor' => $carteira->cor->value,
                    'icone' => $carteira->icone->value,
                    'temConteudo' => $carteira->cotas_count + $carteira->parcelamentos_count + $carteira->grupos_count > 0,
                    'temCotas' => $carteira->cotas_count > 0,
                    'cartao' => $carteira->cartao === null ? null : [
                        'diaVencimento' => $carteira->cartao->dia_vencimento,
                        'diasFechamento' => $carteira->cartao->dias_fechamento,
                        'competencia' => $carteira->cartao->competencia->value,
                        'carteiraVinculadaId' => $carteira->cartao->carteira_vinculada_id,
                        'carteiraVinculadaNome' => $carteira->cartao->carteiraVinculada?->nome,
                        'autorDoEspelho' => $carteira->cartao->cartaoOrigem?->user->name,
                        'contatosDosEspelhos' => $carteira->espelhos->map(fn (Cartao $espelho): string => $espelho->carteira->user->name)->sort()->values()->all(),
                    ],
                ]),
            'cores' => array_column(CorCarteira::cases(), 'value'),
            'icones' => array_column(IconeCarteira::cases(), 'value'),
        ]);
    }

    /**
     * Create a wallet or a card, never as default (RF-021, RF-160).
     */
    public function store(SalvarCarteiraRequest $request, SalvarCartao $salvarCartao): RedirectResponse
    {
        $cartao = $request->cartao();

        if ($cartao === null) {
            $request->user()->carteiras()->create([...$request->dados(), 'padrao' => false]);
        } else {
            $salvarCartao->criar($request->user(), $request->dados(), $cartao);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $cartao === null ? 'Carteira criada.' : 'Cartão criado.']);

        return to_route('carteiras.index');
    }

    /**
     * Rename or change color and icon (RF-022), and the card data of a card (RF-161). The type never
     * changes (RN-190).
     */
    public function update(SalvarCarteiraRequest $request, Carteira $carteira, SalvarCartao $salvarCartao): RedirectResponse
    {
        $dados = $request->dados();
        unset($dados['tipo']);
        $carteira->update($dados);
        $cartao = $request->cartao();

        if ($cartao !== null) {
            $salvarCartao->atualizar($carteira, $cartao);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Carteira atualizada.']);

        return to_route('carteiras.index');
    }

    /**
     * Make the wallet the default one (RF-023).
     */
    public function definirPadrao(Carteira $carteira, DefinirCarteiraPadrao $definirCarteiraPadrao): RedirectResponse
    {
        Gate::authorize('update', $carteira);

        $definirCarteiraPadrao->handle($carteira);

        Inertia::flash('toast', ['type' => 'success', 'message' => "{$carteira->nome} agora é a carteira padrão."]);

        return to_route('carteiras.index');
    }

    /**
     * Delete the wallet, moving or deleting its contents (RF-024).
     */
    public function destroy(ExcluirCarteiraRequest $request, Carteira $carteira, ExcluirCarteira $excluirCarteira): RedirectResponse
    {
        $excluirCarteira->handle($carteira, $request->destino());

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Carteira excluída.']);

        return to_route('carteiras.index');
    }
}
