<?php

namespace App\Http\Controllers;

use App\Actions\Cartoes\CalcularFaturas;
use App\Actions\Cartoes\CartoesDoFormulario;
use App\Actions\Cartoes\FaturaDoMes;
use App\Actions\Cartoes\SaldosVinculados;
use App\Actions\Inicio\CalcularSaldos;
use App\Actions\Inicio\GastosDoMes;
use App\Actions\Inicio\ListarLancamentosDoMes;
use App\Actions\Inicio\PendenciasDaCarteira;
use App\Actions\Inicio\SaldosDasCarteiras;
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
     * opens while it was never seen, or again with "?tutorial=1" (RN-165, RN-167). The full mode also gets
     * the highlights: wallet balances, overdue and upcoming shares and the month's top categories; the
     * panel lists load only on request (RN-171 to RN-175).
     *
     * A card shows its invoice instead of balances (RN-223): the month is the invoice competence, the
     * open invoice's without a month in the URL (R3, RN-253); the list and the spending have the
     * invoice purchases; overdue and upcoming highlights are hidden (RN-224). A common wallet also gets one row per invoice it pays
     * in the month (RN-219).
     */
    public function __invoke(
        InicioRequest $request,
        CalcularSaldos $calcularSaldos,
        ListarLancamentosDoMes $listarLancamentos,
        SaldosDasCarteiras $saldosDasCarteiras,
        PendenciasDaCarteira $pendencias,
        GastosDoMes $gastosDoMes,
        FaturaDoMes $faturaDoMes,
        CalcularFaturas $calcularFaturas,
        CartoesDoFormulario $cartoesDoFormulario,
        SaldosVinculados $saldosVinculados,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $carteira = $request->carteira();
        $hoje = CarbonImmutable::today();
        $noCartao = $carteira->isCartao();
        $mesHoje = $noCartao ? $faturaDoMes->mesDaAberta($carteira, $hoje) : $hoje->startOfMonth();
        $mes = $request->mesInformado() ? $request->mes() : $mesHoje;
        $filtros = $user->modo_simples ? ListarLancamentosDoMes::SEM_FILTROS : $request->filtros();
        // Invoice amounts and card changes of the wallets, computed once for the whole screen.
        $faturas = $calcularFaturas->handle($user, ate: $mes->endOfMonth());
        $vinculados = $saldosVinculados->handle($user, $mes, $faturas);
        $fatura = $noCartao ? $faturaDoMes->handle($user, $carteira, $mes, $hoje, $faturas) : null;

        if ($noCartao) {
            $filtros['status'] = null;
        }
        $perguntarModoSimples = $this->perguntarModoSimples($user, $hoje);

        if ($request->carteiraInformada() === $carteira->id && $user->ultima_carteira_id !== $carteira->id) {
            $user->forceFill(['ultima_carteira_id' => $carteira->id])->save();
        }

        return Inertia::render('Inicio', [
            'carteira' => $this->carteira($carteira),
            'carteiras' => $user->carteiras()
                ->orderByDesc('padrao')
                ->orderBy('tipo', 'desc')
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo', 'cor', 'icone'])
                ->map($this->carteira(...)),
            'cartoes' => (object) $cartoesDoFormulario->handle($user, $hoje),
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
            'mesHoje' => $mesHoje->format('Y-m'),
            'hoje' => $hoje->toDateString(),
            'filtros' => $filtros,
            'saldos' => $noCartao ? null : $calcularSaldos->handle($user, $carteira, $mes, $vinculados),
            'fatura' => $fatura,
            'lancamentos' => $user->modo_simples
                ? $listarLancamentos->semSubsecoes($user, $carteira, $mes, $hoje, $fatura['id'] ?? null)
                : $listarLancamentos->handle($user, $carteira, $mes, $filtros, $hoje, $fatura['id'] ?? null),
            'faturasVinculadas' => $noCartao ? [] : $listarLancamentos->faturasVinculadas(
                $user,
                $carteira,
                $mes,
                $filtros,
                $hoje,
                $faturas,
            ),
            'perguntarModoSimples' => $perguntarModoSimples,
            'mostrarTutorial' => $user->tutorial_visto_em === null || $request->query('tutorial') === '1',
            'destaques' => fn (): ?array => $user->modo_simples ? null : [
                'saldosCarteiras' => (object) $saldosDasCarteiras->handle($user, $mes, $faturas, $vinculados),
                ...($noCartao ? ['vencidas' => null, 'proximas' => null] : $pendencias->resumo($user, $carteira, $hoje)),
                'gastosDoMes' => $gastosDoMes->handle($user, $carteira, $mes, $fatura['id'] ?? null),
            ],
            'cotasVencidas' => Inertia::optional(fn (): array => $noCartao ? [] : $pendencias->listaVencidas($user, $carteira, $hoje)),
            'cotasProximas' => Inertia::optional(fn (): array => $noCartao ? [] : $pendencias->listaProximas($user, $carteira, $hoje)),
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
     * @return array{id: int, nome: string, tipo: string, cor: string, icone: string}
     */
    private function carteira(Carteira $carteira): array
    {
        return [
            'id' => $carteira->id,
            'nome' => $carteira->nome,
            'tipo' => $carteira->tipo->value,
            'cor' => $carteira->cor->value,
            'icone' => $carteira->icone->value,
        ];
    }
}
