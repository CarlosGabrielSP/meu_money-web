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
use App\Models\Fatura;
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
     * in the month (RN-219) and the "a receber" items of the month (RN-243).
     *
     * A member sees a delegated wallet as its owner sees it, under the owner's name (RN-279, CA-203.1):
     * the owner's balances, list, categories, contacts and groups; the form offers only the owner's
     * wallets delegated to the member. No wallet balances highlight, no tour, and the member's last
     * wallet stays (RN-296, CA-203.2, CA-203.7). The interface mode is the member's (RN-297). Each
     * invoice row carries the invoice for the member's "Pagar" and payments (RF-208).
     *
     * A delegated card opens the owner's card screen the same way (RN-319, RN-320): the form offers
     * the owner's wallets and cards delegated to the member, with the data of those cards (RN-321,
     * RN-331); the member never edits the invoice dates (DEC-102).
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
        $delegada = $carteira->user_id !== $user->id;
        /** @var User $dono */
        $dono = $delegada ? $carteira->user : $user;
        $hoje = CarbonImmutable::today();
        $noCartao = $carteira->isCartao();
        $mesHoje = $noCartao ? $faturaDoMes->mesDaAberta($carteira, $hoje) : $hoje->startOfMonth();
        $mes = $request->mesInformado() ? $request->mes() : $mesHoje;
        $filtros = $user->modo_simples ? ListarLancamentosDoMes::SEM_FILTROS : $request->filtros();
        // Invoice amounts and card changes of the wallets, computed once for the whole screen.
        $faturas = $calcularFaturas->handle($dono, ate: $mes->endOfMonth());
        $vinculados = $saldosVinculados->handle($dono, $mes, $faturas);
        $fatura = $noCartao ? $faturaDoMes->handle($dono, $carteira, $mes, $hoje, $faturas) : null;

        if ($fatura !== null && $delegada) {
            // Only the owner edits the invoice dates (RN-320, CA-212.4, DEC-102).
            $fatura['editavel'] = false;
        }

        if ($noCartao) {
            $filtros['status'] = null;
        }
        $perguntarModoSimples = $this->perguntarModoSimples($user, $hoje);

        if (! $delegada && $request->carteiraInformada() === $carteira->id && $user->ultima_carteira_id !== $carteira->id) {
            $user->forceFill(['ultima_carteira_id' => $carteira->id])->save();
        }

        return Inertia::render('Inicio', [
            // The contact's mirror shows its author on the invoice card (RN-246, CA-188.1).
            'carteira' => [...$this->carteira($carteira), 'autorDoEspelho' => $noCartao ? Carteira::autorDoEspelho($carteira->id) : null],
            // Shown to the member under the owner's name, never the other members (RN-280).
            'delegacao' => $delegada ? ['donoId' => $dono->id, 'donoNome' => $dono->name] : null,
            'carteiras' => ($delegada ? $user->carteirasDelegadas()->where('carteiras.user_id', $dono->id) : $user->carteiras())
                ->orderByDesc('padrao')
                ->orderBy('tipo', 'desc')
                ->orderBy('nome')
                ->with('cartao:id,carteira_id,cartao_origem_id')
                ->get(['id', 'nome', 'tipo', 'cor', 'icone'])
                ->map($this->carteira(...)),
            'cartoes' => (object) $cartoesDoFormulario->handle($dono, $hoje, $delegada ? $user->carteirasDelegadasPor($dono) : null),
            'categorias' => Categoria::query()
                ->visiveisPara($dono)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo'])
                ->map(fn (Categoria $categoria): array => [
                    'id' => $categoria->id,
                    'nome' => $categoria->nome,
                    'tipo' => $categoria->tipo->value,
                ]),
            'contatos' => $dono->consultaDeContatos()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (User $contato): array => ['id' => $contato->id, 'nome' => $contato->name]),
            // Only the selection to group uses the groups, and the simple mode has none (RN-161).
            'grupos' => $user->modo_simples ? [] : $carteira->grupos()
                ->where('user_id', $dono->id)
                ->orderBy('nome')
                ->get(['id', 'nome', 'tipo'])
                ->map(fn (Grupo $grupo): array => ['id' => $grupo->id, 'nome' => $grupo->nome, 'tipo' => $grupo->tipo->value]),
            'mes' => $mes->format('Y-m'),
            'mesHoje' => $mesHoje->format('Y-m'),
            'hoje' => $hoje->toDateString(),
            'filtros' => $filtros,
            'saldos' => $noCartao ? null : $calcularSaldos->handle($dono, $carteira, $mes, $vinculados),
            'fatura' => $fatura,
            'lancamentos' => $user->modo_simples
                ? $listarLancamentos->semSubsecoes($dono, $carteira, $mes, $hoje, $fatura['id'] ?? null)
                : $listarLancamentos->handle($dono, $carteira, $mes, $filtros, $hoje, $fatura['id'] ?? null),
            'faturasVinculadas' => $noCartao ? [] : $this->comAcoesDoMembro(
                $listarLancamentos->faturasVinculadas($dono, $carteira, $mes, $filtros, $hoje, $faturas),
                $delegada,
                $dono,
                $carteira,
                $hoje,
                $faturas,
                $faturaDoMes,
            ),
            'aReceber' => $noCartao ? [] : $listarLancamentos->aReceber($carteira, $mes, $filtros),
            'perguntarModoSimples' => $perguntarModoSimples,
            'mostrarTutorial' => ! $delegada && ($user->tutorial_visto_em === null || $request->query('tutorial') === '1'),
            'destaques' => fn (): ?array => $user->modo_simples ? null : [
                'saldosCarteiras' => $delegada ? null : (object) $saldosDasCarteiras->handle($user, $mes, $faturas, $vinculados),
                ...($noCartao ? ['vencidas' => null, 'proximas' => null] : $pendencias->resumo($dono, $carteira, $hoje)),
                'gastosDoMes' => $gastosDoMes->handle($dono, $carteira, $mes, $fatura['id'] ?? null),
            ],
            'cotasVencidas' => Inertia::optional(fn (): array => $noCartao ? [] : $pendencias->listaVencidas($dono, $carteira, $hoje)),
            'cotasProximas' => Inertia::optional(fn (): array => $noCartao ? [] : $pendencias->listaProximas($dono, $carteira, $hoje)),
        ]);
    }

    /**
     * The member's actions on each invoice row of the delegated wallet (RN-281, RN-302, RN-311): the
     * invoice as the card screen has it, for the "Pagar" form, with only the payments that left this
     * wallet. The row items open no form (RN-312, CA-208.14), also while the card is delegated to the
     * member until stage 17.3 (RN-330). The owner's rows do not change (RN-306), so they get null.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  array<int, list<array{fatura: Fatura, compras: int, receitas: int, comprasContatos: int, receitasContatos: int, quitadoPeloAutor: int, pagamentos: int, saldoAnterior: int, aPagar: int, aPagarAutor: int}>>  $faturas  CalcularFaturas
     * @return list<array<string, mixed>>
     */
    private function comAcoesDoMembro(array $linhas, bool $delegada, User $dono, Carteira $carteira, CarbonImmutable $hoje, array $faturas, FaturaDoMes $faturaDoMes): array
    {
        if (! $delegada) {
            return array_map(fn (array $linha): array => [...$linha, 'faturaDoMes' => null], $linhas);
        }

        $cartoes = Carteira::query()->findMany(array_column($linhas, 'carteiraId'))->keyBy('id');

        return array_map(function (array $linha) use ($cartoes, $dono, $carteira, $hoje, $faturas, $faturaDoMes): array {
            /** @var Carteira $cartao */
            $cartao = $cartoes->get($linha['carteiraId']);
            $fatura = $faturaDoMes->handle($dono, $cartao, CarbonImmutable::createFromFormat('!Y-m', (string) $linha['mes']) ?? $hoje, $hoje, $faturas);
            $fatura['pagamentosLista'] = array_values(array_filter(
                $fatura['pagamentosLista'],
                fn (array $pagamento): bool => $pagamento['carteiraId'] === $carteira->id,
            ));

            /** @var list<array<string, mixed>> $itens */
            $itens = $linha['itens'];

            return [
                ...$linha,
                'itens' => array_map(fn (array $item): array => [...$item, 'edicao' => null, 'participacao' => null], $itens),
                'faturaDoMes' => $fatura,
            ];
        }, $linhas);
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
     * A mirror is flagged so the entry form leaves it out (RN-262).
     *
     * @return array{id: int, nome: string, tipo: string, cor: string, icone: string, espelho: bool}
     */
    private function carteira(Carteira $carteira): array
    {
        return [
            'id' => $carteira->id,
            'nome' => $carteira->nome,
            'tipo' => $carteira->tipo->value,
            'cor' => $carteira->cor->value,
            'icone' => $carteira->icone->value,
            'espelho' => $carteira->cartao?->cartao_origem_id !== null,
        ];
    }
}
