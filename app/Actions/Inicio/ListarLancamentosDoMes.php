<?php

namespace App\Actions\Inicio;

use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ListarLancamentosDoMes
{
    /**
     * The user's shares of one wallet and month, split by type and subsection (RN-093, RN-094, RN-116),
     * plus entries the user authored in this wallet without a share of their own (RN-145).
     *
     * The author gets edit data with the split (RF-072); a participant gets the data of their own
     * share, the total and the author (RF-071). Recurring installments show only their number (RN-050).
     *
     * Grouped shares leave the other subsections and form one row per group in "Grupos", with the
     * visible items, their sum and the aggregated status; with a filter that hides some of the
     * group's items in the month the row is marked as filtered (RN-122 to RN-124, RN-129).
     *
     * @param  array{tipo: string|null, categoria: int|null, status: string|null, compartilhado: string|null}  $filtros
     * @return array{receita: array<string, list<array<string, mixed>>>, despesa: array<string, list<array<string, mixed>>>}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $mes, array $filtros, CarbonImmutable $hoje): array
    {
        $secoes = [];

        foreach (['receita', 'despesa'] as $tipo) {
            $secoes[$tipo] = ['avista' => [], 'parceladas' => [], 'recorrentes' => [], 'grupos' => []];
        }

        $inicio = $mes->startOfMonth()->toDateString();
        $fim = $mes->startOfMonth()->addMonth()->toDateString();
        $relacoes = [
            'parcelamento:id,categoria_id,total_parcelas,dia_vencimento,encerrado_em',
            'user:id,name',
            'cotas:id,lancamento_id,user_id,categoria_id,valor,percentual,executado',
            'cotas.user:id,name',
        ];

        $cotas = Cota::query()
            ->select('cotas.*')
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.data', '>=', $inicio)
            ->where('lancamentos.data', '<', $fim)
            ->when($filtros['tipo'], fn ($query, string $tipo) => $query->where('lancamentos.tipo', $tipo))
            ->when($filtros['categoria'], fn ($query, int $categoria) => $query->where('cotas.categoria_id', $categoria))
            ->when($filtros['status'], fn ($query, string $status) => $query->where('cotas.executado', $status === 'executado'))
            ->when($filtros['compartilhado'], fn ($query, string $compartilhado) => $compartilhado === 'sim'
                ? $query->whereNotNull('lancamentos.modo_rateio')
                : $query->whereNull('lancamentos.modo_rateio'))
            ->with(['categoria:id,nome', 'grupo:id,nome', ...array_map(fn (string $relacao): string => "lancamento.{$relacao}", $relacoes)])
            ->get();

        $semCota = $filtros['categoria'] !== null || $filtros['status'] !== null || $filtros['compartilhado'] === 'nao'
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

        $categoriasVisiveis = $semCota->isEmpty() ? collect() : Categoria::query()->visiveisPara($user)->pluck('id');

        $itens = $cotas->map(fn (Cota $cota): array => [$cota->lancamento, $cota])
            ->concat($semCota->map(fn (Lancamento $lancamento): array => [$lancamento, null]))
            ->sortBy(fn (array $par): array => [$par[0]->data->toDateString(), $par[0]->created_at?->toDateTimeString(), $par[0]->id]);

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
                ->where('lancamentos.data', '>=', $inicio)
                ->where('lancamentos.data', '<', $fim)
                ->groupBy('cotas.grupo_id')
                ->selectRaw('cotas.grupo_id, count(*) as total')
                ->pluck('total', 'grupo_id');

        foreach ($grupos as $tipo => $doTipo) {
            foreach ($doTipo as $linha) {
                $secoes[$tipo]['grupos'][] = $this->grupo($linha['grupo'], $linha['itens'], $totaisNoMes);
            }
        }

        return $secoes;
    }

    /**
     * One row of the "Grupos" subsection (RN-122, RN-129).
     *
     * @param  list<array<string, mixed>>  $itens
     * @param  Collection<int, mixed>|null  $totaisNoMes  shares of each group in the month without filters, when a filter is active
     * @return array<string, mixed>
     */
    private function grupo(Grupo $grupo, array $itens, ?Collection $totaisNoMes): array
    {
        $executados = count(array_filter($itens, fn (array $item): bool => $item['executado'] === true));

        return [
            'chave' => "g{$grupo->id}",
            'id' => $grupo->id,
            'nome' => $grupo->nome,
            'quantidade' => count($itens),
            'valor' => array_sum(array_column($itens, 'valor')),
            'status' => match ($executados) {
                0 => 'pendente',
                count($itens) => 'executado',
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

        return [
            'chave' => $cota === null ? "l{$lancamento->id}" : "c{$cota->id}",
            'cotaId' => $cota?->id,
            'grupoId' => $cota?->grupo_id,
            'lancamentoId' => $lancamento->id,
            'data' => $lancamento->data->toDateString(),
            'descricao' => $lancamento->descricao,
            'categoria' => $cota?->categoria->nome,
            'valor' => $cota === null ? 0 : $cota->valor,
            'total' => $lancamento->valor,
            'autor' => $autor ? null : $lancamento->user->name,
            'executado' => $cota !== null && $cota->executado,
            'vencido' => $cota !== null && ! $cota->executado && $lancamento->data->lessThan($hoje),
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
                    'bloqueado' => $lancamento->cotas->contains(fn (Cota $outra): bool => $outra->user_id !== $user->id && $outra->executado),
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
