<?php

namespace App\Http\Controllers;

use App\Actions\Inicio\CalcularSaldos;
use App\Actions\Inicio\ListarLancamentosDoMes;
use App\Http\Requests\InicioRequest;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Grupo;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class InicioController extends Controller
{
    /**
     * Main screen: one wallet, one month, balances and the month list (RF-080 to RF-085). The simple
     * mode gets the list without subsections and ignores the filters of the URL (RN-161). The tour
     * opens while it was never seen, or again with "?tutorial=1" (RN-165, RN-167).
     */
    public function __invoke(InicioRequest $request, CalcularSaldos $calcularSaldos, ListarLancamentosDoMes $listarLancamentos): Response
    {
        /** @var User $user */
        $user = $request->user();
        $carteira = $request->carteira();
        $mes = $request->mes();
        $hoje = CarbonImmutable::today();
        $filtros = $user->modo_simples ? ListarLancamentosDoMes::SEM_FILTROS : $request->filtros();
        $perguntarModoSimples = $this->perguntarModoSimples($user, $hoje);

        if ($request->carteiraInformada() === $carteira->id && $user->ultima_carteira_id !== $carteira->id) {
            $user->forceFill(['ultima_carteira_id' => $carteira->id])->save();
        }

        return Inertia::render('Inicio', [
            'carteira' => $this->carteira($carteira),
            'carteiras' => $user->carteiras()
                ->orderByDesc('padrao')
                ->orderBy('nome')
                ->get(['id', 'nome', 'cor', 'icone'])
                ->map($this->carteira(...)),
            'categorias' => Categoria::query()
                ->visiveisPara($user)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo'])
                ->map(fn (Categoria $categoria): array => [
                    'id' => $categoria->id,
                    'nome' => $categoria->nome,
                    'tipo' => $categoria->tipo->value,
                ]),
            'contatos' => $user->consultaDeContatos()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $contato): array => ['id' => $contato->id, 'nome' => $contato->name]),
            // Only the selection to group uses the groups, and the simple mode has none (RN-161).
            'grupos' => $user->modo_simples ? [] : $carteira->grupos()
                ->where('user_id', $user->id)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo'])
                ->map(fn (Grupo $grupo): array => ['id' => $grupo->id, 'nome' => $grupo->nome, 'tipo' => $grupo->tipo->value]),
            'mes' => $mes->format('Y-m'),
            'hoje' => $hoje->toDateString(),
            'filtros' => $filtros,
            'saldos' => $calcularSaldos->handle($user, $carteira, $mes),
            'lancamentos' => $user->modo_simples
                ? $listarLancamentos->semSubsecoes($user, $carteira, $mes, $hoje)
                : $listarLancamentos->handle($user, $carteira, $mes, $filtros, $hoje),
            'perguntarModoSimples' => $perguntarModoSimples,
            'mostrarTutorial' => $user->tutorial_visto_em === null || $request->query('tutorial') === '1',
        ]);
    }

    /**
     * First access (RN-155, RN-156): when the question applies it stays open until answered, even
     * after a reload (CA-111.7); otherwise the first access ends on this visit.
     */
    private function perguntarModoSimples(User $user, CarbonImmutable $hoje): bool
    {
        if ($user->primeiro_acesso_em !== null) {
            return false;
        }

        if ($user->devePerguntarModoSimples($hoje)) {
            return true;
        }

        $user->forceFill(['primeiro_acesso_em' => now()])->save();

        return false;
    }

    /**
     * @return array{id: int, nome: string, cor: string, icone: string}
     */
    private function carteira(Carteira $carteira): array
    {
        return [
            'id' => $carteira->id,
            'nome' => $carteira->nome,
            'cor' => $carteira->cor->value,
            'icone' => $carteira->icone->value,
        ];
    }
}
