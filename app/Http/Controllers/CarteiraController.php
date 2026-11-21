<?php

namespace App\Http\Controllers;

use App\Actions\Carteiras\DefinirCarteiraPadrao;
use App\Actions\Carteiras\ExcluirCarteira;
use App\Actions\Cartoes\SalvarCartao;
use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Enums\StatusMembro;
use App\Http\Requests\Carteiras\ExcluirCarteiraRequest;
use App\Http\Requests\Carteiras\SalvarCarteiraRequest;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\CarteiraMembro;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
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
     *
     * Delegation (RF-200 to RF-202, RF-211): each common wallet and own card carries its members and
     * invitations (a mirror none, RN-313), and the page carries the owner's contacts for the invitation
     * and the "Delegadas a mim" section.
     */
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('carteiras/Index', [
            'carteiras' => $request->user()->carteiras()
                ->orderByDesc('padrao')
                ->orderBy('tipo', 'desc')
                ->orderBy('nome')
                ->withCount(['cotas', 'parcelamentos', 'grupos'])
                ->with(['cartao.carteiraVinculada:id,nome', 'cartao.cartaoOrigem.user:id,name', 'espelhos.carteira.user:id,name', 'membros.user:id,name'])
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
                    'espelho' => $carteira->isEspelho(),
                    'membros' => $carteira->isEspelho() ? [] : $this->membros($carteira),
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
            'contatos' => $user->consultaDeContatos()->orderBy('name')->get(['id', 'name'])
                ->map(fn (User $contato): array => ['id' => $contato->id, 'nome' => $contato->name]),
            'delegadas' => $this->delegadas($user),
            'cores' => array_column(CorCarteira::cases(), 'value'),
            'icones' => array_column(IconeCarteira::cases(), 'value'),
        ]);
    }

    /**
     * Members of an owner's wallet: accepted, pending, then refused, each by name (nota 07, DEC-098).
     *
     * @return array<int, array{id: int, userId: int, nome: string, status: string}>
     */
    private function membros(Carteira $carteira): array
    {
        $ordem = [StatusMembro::Aceito->value => 0, StatusMembro::Pendente->value => 1, StatusMembro::Recusado->value => 2];

        return $carteira->membros
            ->sortBy([
                fn (CarteiraMembro $a, CarteiraMembro $b): int => $ordem[$a->status->value] <=> $ordem[$b->status->value],
                fn (CarteiraMembro $a, CarteiraMembro $b): int => strnatcasecmp($a->user->name, $b->user->name),
            ])
            ->map(fn (CarteiraMembro $membro): array => [
                'id' => $membro->id,
                'userId' => $membro->user_id,
                'nome' => $membro->user->name,
                'status' => $membro->status->value,
            ])
            ->values()
            ->all();
    }

    /**
     * "Delegadas a mim": pending invitations and accepted wallets and cards (RN-319) of active owners,
     * never a mirror (RN-313), by owner name (owner id keeps two owners with the same name apart) and
     * wallet name (RN-279, RN-288, nota 07, DEC-098).
     *
     * @return array{convites: array<int, array{id: int, carteiraId: int, nome: string, cor: string, icone: string, donoId: int, dono: string}>, carteiras: array<int, array{id: int, carteiraId: int, nome: string, cor: string, icone: string, donoId: int, dono: string}>}
     */
    private function delegadas(User $user): array
    {
        $registros = $user->delegacoes()
            ->whereIn('status', [StatusMembro::Pendente, StatusMembro::Aceito])
            ->whereHas('carteira.user', fn (Builder $dono) => $dono->whereNull('desativado_em'))
            ->whereDoesntHave('carteira.cartao', fn (Builder $cartao) => $cartao->whereNotNull('cartao_origem_id'))
            ->with('carteira:id,user_id,nome,cor,icone', 'carteira.user:id,name')
            ->get()
            ->sortBy([
                fn (CarteiraMembro $a, CarteiraMembro $b): int => strnatcasecmp($a->carteira->user->name, $b->carteira->user->name),
                fn (CarteiraMembro $a, CarteiraMembro $b): int => $a->carteira->user_id <=> $b->carteira->user_id,
                fn (CarteiraMembro $a, CarteiraMembro $b): int => strnatcasecmp($a->carteira->nome, $b->carteira->nome),
            ]);

        $linha = fn (CarteiraMembro $membro): array => [
            'id' => $membro->id,
            'carteiraId' => $membro->carteira_id,
            'nome' => $membro->carteira->nome,
            'cor' => $membro->carteira->cor->value,
            'icone' => $membro->carteira->icone->value,
            'donoId' => $membro->carteira->user_id,
            'dono' => $membro->carteira->user->name,
        ];

        return [
            'convites' => $registros->where('status', StatusMembro::Pendente)->map($linha)->values()->all(),
            'carteiras' => $registros->where('status', StatusMembro::Aceito)->map($linha)->values()->all(),
        ];
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
