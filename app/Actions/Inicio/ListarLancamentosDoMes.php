<?php

namespace App\Actions\Inicio;

use App\Enums\OrigemQuitacao;
use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\QuitacaoContato;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListarLancamentosDoMes
{
    /**
     * The filters of the main screen when none applies (RN-094, RN-161).
     *
     * @var array{tipo: null, categoria: null, status: null, compartilhado: null}
     */
    public const SEM_FILTROS = ['tipo' => null, 'categoria' => null, 'status' => null, 'compartilhado' => null];

    /**
     * The user's shares of one wallet and month, split by type and subsection (RN-093, RN-094, RN-116),
     * each with the icon of its category (RN-176),
     * plus entries the user authored in this wallet without a share of their own (RN-145).
     *
     * The author gets edit data with the split (RF-072), locked when a participant executed a share or a
     * contact's part was settled, with the names of those contacts (RN-250, nota 07); a participant gets the data of their own
     * share, the total and the author (RF-071). Recurring installments show only their number (RN-050).
     *
     * Grouped shares leave the other subsections and form one row per group in "Grupos", with the
     * visible items, their sum and the aggregated status; with a filter that hides some of the
     * group's items in the month the row is marked as filtered (RN-122 to RN-124, RN-129).
     *
     * A card lists the shares of the invoice due in the month instead of the month dates, without
     * status nor status filter; groups have no aggregated status (RN-194, RN-211, RN-223).
     *
     * @param  array{tipo: string|null, categoria: int|null, status: string|null, compartilhado: string|null}  $filtros
     * @param  int|null  $faturaId  invoice of the month, for a card; null when it was never created
     * @return array{receita: array<string, list<array<string, mixed>>>, despesa: array<string, list<array<string, mixed>>>}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $mes, array $filtros, CarbonImmutable $hoje, ?int $faturaId = null): array
    {
        if ($carteira->isCartao()) {
            $filtros['status'] = null;
        }

        $secoes = [];

        foreach (['receita', 'despesa'] as $tipo) {
            $secoes[$tipo] = ['avista' => [], 'parceladas' => [], 'recorrentes' => [], 'grupos' => []];
        }

        [$inicio, $fim] = $this->limites($mes);
        $itens = $this->itens($user, $carteira, $inicio, $fim, $filtros, comGrupo: true, faturaId: $faturaId);
        $categoriasVisiveis = $this->categoriasVisiveis($user, $itens);

        $grupos = [];

        foreach ($itens as [$lancamento, $cota]) {
            if ($cota?->grupo !== null) {
                $grupos[$lancamento->tipo->value][$cota->grupo->id]['grupo'] = $cota->grupo;
                $grupos[$lancamento->tipo->value][$cota->grupo->id]['itens'][] = $this->item($user, $lancamento, $cota, $hoje, $categoriasVisiveis);

                continue;
            }

            $serie = $lancamento->parcelamento_id !== null ? $lancamento->parcelamento : null;
            $subsecao = match (true) {
                $serie === null => 'avista',
                $serie->isRecorrente() => 'recorrentes',
                default => 'parceladas',
            };

            $secoes[$lancamento->tipo->value][$subsecao][] = $this->item($user, $lancamento, $cota, $hoje, $categoriasVisiveis);
        }

        $filtrosQueOcultamItens = array_filter([$filtros['categoria'], $filtros['status'], $filtros['compartilhado']], fn (mixed $valor): bool => $valor !== null);
        $totaisNoMes = $grupos === [] || $filtrosQueOcultamItens === []
            ? null
            : Cota::query()
                ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
                ->where('cotas.user_id', $user->id)
                ->where('cotas.carteira_id', $carteira->id)
                ->whereNotNull('cotas.grupo_id')
                ->when(
                    $carteira->isCartao(),
                    fn ($query) => $query->where('cotas.fatura_id', $faturaId ?? 0),
                    fn ($query) => $query->where('lancamentos.data', '>=', $inicio)->where('lancamentos.data', '<', $fim),
                )
                ->groupBy('cotas.grupo_id')
                ->selectRaw('cotas.grupo_id, count(*) as total')
                ->pluck('total', 'grupo_id');

        foreach ($grupos as $tipo => $doTipo) {
            foreach ($doTipo as $linha) {
                $secoes[$tipo]['grupos'][] = $this->grupo($linha['grupo'], $linha['itens'], $totaisNoMes, $carteira->isCartao());
            }
        }

        return $secoes;
    }

    /**
     * The month list of the simple mode: Receitas and Despesas without subsections, by date and, on
     * the same day, by creation. Grouped items are plain items and filters never apply (RN-161).
     *
     * @param  int|null  $faturaId  invoice of the month, for a card (RN-212)
     * @return array{receita: list<array<string, mixed>>, despesa: list<array<string, mixed>>}
     */
    public function semSubsecoes(User $user, Carteira $carteira, CarbonImmutable $mes, CarbonImmutable $hoje, ?int $faturaId = null): array
    {
        $secoes = ['receita' => [], 'despesa' => []];
        [$inicio, $fim] = $this->limites($mes);
        $itens = $this->itens($user, $carteira, $inicio, $fim, self::SEM_FILTROS, comGrupo: false, faturaId: $faturaId);
        $categoriasVisiveis = $this->categoriasVisiveis($user, $itens);

        foreach ($itens as [$lancamento, $cota]) {
            $secoes[$lancamento->tipo->value][] = $this->item($user, $lancamento, $cota, $hoje, $categoriasVisiveis);
        }

        return $secoes;
    }

    /**
     * The user's shares in the month plus the entries they authored here without a share of their
     * own, sorted by date and creation (RN-093, RN-145). The group is loaded only for the
     * subsections of the full mode (RN-122). A card takes the shares of its invoice (RN-223).
     *
     * @param  array{tipo: string|null, categoria: int|null, status: string|null, compartilhado: string|null}  $filtros
     * @return Collection<int, array{0: Lancamento, 1: Cota|null}>
     */
    private function itens(User $user, Carteira $carteira, string $inicio, string $fim, array $filtros, bool $comGrupo, ?int $faturaId = null): Collection
    {
        $noCartao = $carteira->isCartao();

        $relacoes = [
            'parcelamento:id,categoria_id,total_parcelas,dia_vencimento,encerrado_em',
            'user:id,name',
            'cotas:id,lancamento_id,user_id,fatura_id,categoria_id,valor,percentual,executado',
            'cotas.user:id,name',
        ];

        $cotas = Cota::query()
            ->select('cotas.*')
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->when(
                $noCartao,
                fn ($query) => $query->where('cotas.fatura_id', $faturaId ?? 0),
                fn ($query) => $query->where('lancamentos.data', '>=', $inicio)->where('lancamentos.data', '<', $fim),
            )
            ->when($filtros['tipo'], fn ($query, string $tipo) => $query->where('lancamentos.tipo', $tipo))
            ->when($filtros['categoria'], fn ($query, int $categoria) => $query->where('cotas.categoria_id', $categoria))
            ->when($filtros['status'], fn ($query, string $status) => $query->where('cotas.executado', $status === 'executado'))
            ->when($filtros['compartilhado'], fn ($query, string $compartilhado) => $compartilhado === 'sim'
                ? $query->whereNotNull('lancamentos.modo_rateio')
                : $query->whereNull('lancamentos.modo_rateio'))
            ->with(['categoria:id,nome,icone', 'fatura:id,fechamento', ...($comGrupo ? ['grupo:id,nome'] : []), ...array_map(fn (string $relacao): string => "lancamento.{$relacao}", $relacoes)])
            ->get();

        $semCota = $noCartao || $filtros['categoria'] !== null || $filtros['status'] !== null || $filtros['compartilhado'] === 'nao'
            ? collect()
            : Lancamento::query()
                ->where('user_id', $user->id)
                ->where('carteira_id', $carteira->id)
                ->where('data', '>=', $inicio)
                ->where('data', '<', $fim)
                ->whereDoesntHave('cotas', fn (Builder $query) => $query->where('user_id', $user->id))
                ->when($filtros['tipo'], fn ($query, string $tipo) => $query->where('tipo', $tipo))
                ->with($relacoes)
                ->get();

        Cota::marcarQuitadas($cotas->flatMap(fn (Cota $cota) => $cota->lancamento->cotas)->concat($semCota->flatMap->cotas));

        return $cotas->map(fn (Cota $cota): array => [$cota->lancamento, $cota])
            ->concat($semCota->map(fn (Lancamento $lancamento): array => [$lancamento, null]))
            ->sortBy(fn (array $par): array => [$par[0]->data->toDateString(), $par[0]->created_at?->toDateTimeString(), $par[0]->id])
            ->values();
    }

    /**
     * One row per invoice paid by the common wallet and due in the month, with the user's shares
     * (RN-219, DEC-087): card name, due date, purchases minus income of the invoice, the situation
     * ("a pagar" without payment, "parcial" with payment, "quitada" when nothing is left to pay) and
     * the items, without status. Invoices without shares have no row. With any filter active except
     * type = expense, there is no row. The card shares never appear as items of the wallet. Tapping
     * opens the card in the invoice competence month (RN-253).
     *
     * @param  array{tipo: string|null, categoria: int|null, status: string|null, compartilhado: string|null}  $filtros
     * @param  array<int, list<array{fatura: Fatura, pagamentos: int, aPagar: int}>>  $faturas  amounts of the user's invoices (CalcularFaturas)
     * @return list<array<string, mixed>>
     */
    public function faturasVinculadas(User $user, Carteira $carteira, CarbonImmutable $mes, array $filtros, CarbonImmutable $hoje, array $faturas): array
    {
        $outros = array_filter([$filtros['categoria'], $filtros['status'], $filtros['compartilhado']], fn (mixed $valor): bool => $valor !== null);

        if ($carteira->isCartao() || $outros !== [] || ($filtros['tipo'] !== null && $filtros['tipo'] !== 'despesa')) {
            return [];
        }

        [$inicio, $fim] = $this->limites($mes);
        $situacoes = collect($faturas)->flatten(1)->keyBy(fn (array $linha): int => $linha['fatura']->id);

        $cotas = Cota::query()
            ->select('cotas.*')
            ->join('faturas', 'faturas.id', '=', 'cotas.fatura_id')
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('faturas.carteira_vinculada_id', $carteira->id)
            ->where('faturas.vencimento', '>=', $inicio)
            ->where('faturas.vencimento', '<', $fim)
            ->with([
                'categoria:id,nome,icone',
                'fatura:id,carteira_id,referencia,fechamento,vencimento',
                'fatura.carteira:id,nome,cor,icone',
                'fatura.carteira.cartao:id,carteira_id,dia_vencimento,dias_fechamento,competencia',
                'lancamento.parcelamento:id,categoria_id,total_parcelas,dia_vencimento,encerrado_em',
                'lancamento.user:id,name',
                'lancamento.cotas:id,lancamento_id,user_id,fatura_id,categoria_id,valor,percentual,executado',
                'lancamento.cotas.user:id,name',
            ])
            ->orderBy('lancamentos.data')
            ->orderBy('lancamentos.id')
            ->get();
        Cota::marcarQuitadas($cotas->flatMap(fn (Cota $cota) => $cota->lancamento->cotas));

        return array_values($cotas
            ->groupBy('fatura_id')
            ->map(function (Collection $doCartao) use ($user, $hoje, $situacoes): array {
                /** @var Cota $primeira */
                $primeira = $doCartao->first();
                /** @var Fatura $fatura */
                $fatura = $primeira->fatura;
                $situacao = $situacoes->get($fatura->id);
                $aPagar = (int) ($situacao['aPagar'] ?? 0);
                $valor = $doCartao->sum(fn (Cota $cota): int => $cota->lancamento->tipo === TipoLancamento::Receita ? -$cota->valor : $cota->valor);

                return [
                    'chave' => "f{$fatura->id}",
                    'carteiraId' => $fatura->carteira_id,
                    'nome' => $fatura->carteira->nome,
                    'cor' => $fatura->carteira->cor->value,
                    'icone' => $fatura->carteira->icone->value,
                    'mes' => $fatura->carteira->cartao?->competenciaDa($fatura->referencia)->format('Y-m') ?? $fatura->referencia->format('Y-m'),
                    'vencimento' => $fatura->vencimento->toDateString(),
                    'valor' => $valor,
                    'situacao' => match (true) {
                        $aPagar <= 0 => 'quitada',
                        (int) ($situacao['pagamentos'] ?? 0) > 0 => 'parcial',
                        default => 'a_pagar',
                    },
                    'itens' => $doCartao->map(fn (Cota $cota): array => $this->item($user, $cota->lancamento, $cota, $hoje, collect()))->values()->all(),
                ];
            })
            ->sortBy(fn (array $linha): array => [$linha['vencimento'], $linha['nome']])
            ->all());
    }

    /**
     * The "a receber" items of a common wallet in the month (RN-216 to RN-218, RN-242, RN-243, RN-265):
     * one per settlement of origin `autor` paid from the wallet, in the month of the payment date, with
     * the contact's name, the total, what was received and whether a manual receipt exists. With any
     * filter active, except type = income, there are none. They never enter the month totals.
     *
     * @param  array{tipo: string|null, categoria: int|null, status: string|null, compartilhado: string|null}  $filtros
     * @return list<array{chave: string, id: int, data: string, nome: string, valor: int, recebido: int, manual: bool}>
     */
    public function aReceber(Carteira $carteira, CarbonImmutable $mes, array $filtros): array
    {
        $outros = array_filter([$filtros['categoria'], $filtros['status'], $filtros['compartilhado']], fn (mixed $valor): bool => $valor !== null);

        if ($carteira->isCartao() || $outros !== [] || ($filtros['tipo'] !== null && $filtros['tipo'] !== 'receita')) {
            return [];
        }

        [$inicio, $fim] = $this->limites($mes);

        return array_values(QuitacaoContato::query()
            ->join('pagamentos_fatura', 'pagamentos_fatura.id', '=', 'quitacoes_contato.pagamento_fatura_id')
            ->join('users', 'users.id', '=', 'quitacoes_contato.contato_id')
            ->where('pagamentos_fatura.carteira_id', $carteira->id)
            ->where('quitacoes_contato.origem', OrigemQuitacao::Autor->value)
            ->where('pagamentos_fatura.data', '>=', $inicio)
            ->where('pagamentos_fatura.data', '<', $fim)
            ->select(['quitacoes_contato.*', 'pagamentos_fatura.data as data_pagamento', 'users.name as nome_contato'])
            ->withSum('recebimentos', 'valor')
            ->withExists(['recebimentos as tem_manual' => fn (Builder $query) => $query->whereNull('pagamento_fatura_id')])
            ->orderBy('pagamentos_fatura.data')
            ->orderBy('quitacoes_contato.id')
            ->get()
            ->map(fn (QuitacaoContato $quitacao): array => [
                'chave' => "r{$quitacao->id}",
                'id' => $quitacao->id,
                'data' => CarbonImmutable::parse($quitacao->getAttribute('data_pagamento'))->toDateString(),
                'nome' => (string) $quitacao->getAttribute('nome_contato'),
                'valor' => $quitacao->valor,
                'recebido' => (int) $quitacao->getAttribute('recebimentos_sum_valor'),
                'manual' => (bool) $quitacao->getAttribute('tem_manual'),
            ])
            ->all());
    }

    /**
     * First day of the month and of the next one; the next month start is the upper bound because
     * the date cast stores "Y-m-d 00:00:00" (RN-093).
     *
     * @return array{0: string, 1: string}
     */
    private function limites(CarbonImmutable $mes): array
    {
        return [$mes->startOfMonth()->toDateString(), $mes->startOfMonth()->addMonth()->toDateString()];
    }

    /**
     * Ids of the categories the user sees, needed only for entries without a share of the user (RN-145).
     *
     * @param  Collection<int, array{0: Lancamento, 1: Cota|null}>  $itens
     * @return Collection<int, mixed>
     */
    private function categoriasVisiveis(User $user, Collection $itens): Collection
    {
        return $itens->contains(fn (array $par): bool => $par[1] === null)
            ? Categoria::query()->visiveisPara($user)->pluck('id')
            : collect();
    }

    /**
     * One row of the "Grupos" subsection (RN-122, RN-129).
     *
     * @param  list<array<string, mixed>>  $itens
     * @param  Collection<int, mixed>|null  $totaisNoMes  shares of each group in the month without filters, when a filter is active
     * @return array<string, mixed>
     */
    private function grupo(Grupo $grupo, array $itens, ?Collection $totaisNoMes, bool $noCartao = false): array
    {
        $executados = count(array_filter($itens, fn (array $item): bool => $item['executado'] === true));

        return [
            'chave' => "g{$grupo->id}",
            'id' => $grupo->id,
            'nome' => $grupo->nome,
            'quantidade' => count($itens),
            'valor' => array_sum(array_column($itens, 'valor')),
            'status' => match (true) {
                $noCartao => null,
                $executados === 0 => 'pendente',
                $executados === count($itens) => 'executado',
                default => 'parcial',
            },
            'filtrado' => $totaisNoMes !== null && (int) $totaisNoMes->get($grupo->id, 0) > count($itens),
            'itens' => $itens,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $categoriasVisiveis  ids of categories the user sees, for an author without a share
     * @return array<string, mixed>
     */
    private function item(User $user, Lancamento $lancamento, ?Cota $cota, CarbonImmutable $hoje, Collection $categoriasVisiveis): array
    {
        $serie = $lancamento->parcelamento_id !== null ? $lancamento->parcelamento : null;
        $autor = $lancamento->user_id === $user->id;
        $noCartao = $cota?->fatura_id !== null;

        return [
            'chave' => $cota === null ? "l{$lancamento->id}" : "c{$cota->id}",
            'cotaId' => $cota?->id,
            'grupoId' => $cota?->grupo_id,
            'lancamentoId' => $lancamento->id,
            'data' => $lancamento->data->toDateString(),
            'descricao' => $lancamento->descricao,
            'categoria' => $cota?->categoria->nome,
            'categoriaIcone' => $cota?->categoria->icone->value,
            'valor' => $cota === null ? 0 : $cota->valor,
            'total' => $lancamento->valor,
            'autor' => $autor ? null : $lancamento->user->name,
            'executado' => $cota !== null && $cota->executado,
            'vencido' => $cota !== null && ! $noCartao && ! $cota->executado && $lancamento->data->lessThan($hoje),
            'cartao' => $noCartao,
            'faturaFechada' => $noCartao && $cota->fatura !== null && $cota->fatura->isFechada($hoje),
            'parcela' => match (true) {
                $serie === null => null,
                $serie->isRecorrente() => (string) $lancamento->numero_parcela,
                default => "{$lancamento->numero_parcela}/{$serie->total_parcelas}",
            },
            'recorrente' => $serie?->isRecorrente() ?? false,
            'compartilhado' => $lancamento->modo_rateio !== null,
            'temAnexo' => $lancamento->anexo_caminho !== null,
            'serie' => $serie === null ? null : [
                'id' => $serie->id,
                'recorrente' => $serie->isRecorrente(),
                'numero' => $lancamento->numero_parcela,
                'total' => $serie->total_parcelas,
                'diaVencimento' => $serie->dia_vencimento,
                'encerradaEm' => $serie->encerrado_em?->toDateString(),
            ],
            'edicao' => ! $autor ? null : [
                'tipo' => $lancamento->tipo->value,
                'descricao' => $lancamento->descricao,
                'valor' => $lancamento->valor,
                'data' => $lancamento->data->toDateString(),
                'categoriaId' => $cota !== null ? $cota->categoria_id : $this->categoriaSemCota($lancamento, $serie, $categoriasVisiveis),
                'carteiraId' => $cota !== null ? $cota->carteira_id : $lancamento->carteira_id,
                'observacao' => $lancamento->observacao,
                'executado' => $cota !== null && $cota->executado,
                'anexoNome' => $lancamento->anexo_nome,
                'rateio' => $lancamento->modo_rateio === null ? null : [
                    'modo' => $lancamento->modo_rateio->value,
                    'autorParticipa' => $cota !== null,
                    'bloqueado' => $lancamento->cotas->contains(fn (Cota $outra): bool => $outra->user_id !== $user->id && ($outra->executado || $outra->isQuitada())),
                    'avisoQuitacao' => $this->avisoQuitacao($user, $lancamento),
                    'participantes' => $lancamento->cotas->sortBy('id')->values()->map(fn (Cota $outra): array => [
                        'userId' => $outra->user_id,
                        'nome' => $outra->user->name,
                        'valor' => $outra->valor,
                        'percentual' => $outra->percentual,
                        'executado' => $outra->executado,
                    ])->all(),
                ],
            ],
            'participacao' => $autor || $cota === null ? null : [
                'carteiraId' => $cota->carteira_id,
                'categoriaId' => $cota->categoria_id,
                'executado' => $cota->executado,
                'observacao' => $lancamento->observacao,
                'anexoNome' => $lancamento->anexo_nome,
            ],
        ];
    }

    /**
     * Lock hint of a purchase whose contacts' parts were settled, the server message of RN-258; null
     * when none was (RN-250, nota 07).
     */
    private function avisoQuitacao(User $user, Lancamento $lancamento): ?string
    {
        $nomes = $lancamento->cotas
            ->filter(fn (Cota $outra): bool => $outra->user_id !== $user->id && $outra->isQuitada())
            ->map(fn (Cota $outra): string => $outra->user->name);

        return $nomes->isEmpty() ? null : QuitacaoContato::mensagemDeTrava($nomes);
    }

    /**
     * Category shown in the form of an author without a share: the first participant's when the author
     * can see it, else the series category, else none (RN-145).
     *
     * @param  Collection<int, mixed>  $categoriasVisiveis
     */
    private function categoriaSemCota(Lancamento $lancamento, ?Parcelamento $serie, Collection $categoriasVisiveis): ?int
    {
        $daParticipante = $lancamento->cotas->sortBy('id')->first()?->categoria_id;

        return match (true) {
            $daParticipante !== null && $categoriasVisiveis->contains($daParticipante) => $daParticipante,
            $serie !== null => $serie->categoria_id,
            default => null,
        };
    }
}
