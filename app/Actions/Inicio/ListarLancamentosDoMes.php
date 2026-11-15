<?php

namespace App\Actions\Inicio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;

class ListarLancamentosDoMes
{
    /**
     * The user's shares of one wallet and month, split by type and subsection (RN-093, RN-094, RN-116).
     *
     * Groups (RN-093 last subsection) are part of phase 8. Shared entries and installments are not
     * editable here until their own forms exist (phases 4 and 7).
     *
     * @param  array{tipo: string|null, categoria: int|null, status: string|null, compartilhado: string|null}  $filtros
     * @return array{receita: array<string, list<array<string, mixed>>>, despesa: array<string, list<array<string, mixed>>>}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $mes, array $filtros, CarbonImmutable $hoje): array
    {
        $secoes = [];

        foreach (['receita', 'despesa'] as $tipo) {
            $secoes[$tipo] = ['avista' => [], 'parceladas' => [], 'recorrentes' => []];
        }

        $cotas = Cota::query()
            ->select('cotas.*')
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.data', '>=', $mes->startOfMonth()->toDateString())
            ->where('lancamentos.data', '<', $mes->startOfMonth()->addMonth()->toDateString())
            ->when($filtros['tipo'], fn ($query, string $tipo) => $query->where('lancamentos.tipo', $tipo))
            ->when($filtros['categoria'], fn ($query, int $categoria) => $query->where('cotas.categoria_id', $categoria))
            ->when($filtros['status'], fn ($query, string $status) => $query->where('cotas.executado', $status === 'executado'))
            ->when($filtros['compartilhado'], fn ($query, string $compartilhado) => $compartilhado === 'sim'
                ? $query->whereNotNull('lancamentos.modo_rateio')
                : $query->whereNull('lancamentos.modo_rateio'))
            ->with(['lancamento.parcelamento:id,total_parcelas', 'categoria:id,nome'])
            ->orderBy('lancamentos.data')
            ->orderBy('lancamentos.created_at')
            ->orderBy('lancamentos.id')
            ->get();

        foreach ($cotas as $cota) {
            $lancamento = $cota->lancamento;
            $serie = $lancamento->parcelamento_id !== null ? $lancamento->parcelamento : null;
            $subsecao = match (true) {
                $serie === null => 'avista',
                $serie->isRecorrente() => 'recorrentes',
                default => 'parceladas',
            };
            $editavel = $lancamento->user_id === $user->id && $lancamento->motivoEdicaoIndisponivel() === null;

            $secoes[$lancamento->tipo->value][$subsecao][] = [
                'cotaId' => $cota->id,
                'lancamentoId' => $lancamento->id,
                'data' => $lancamento->data->toDateString(),
                'descricao' => $lancamento->descricao,
                'categoria' => $cota->categoria->nome,
                'valor' => $cota->valor,
                'executado' => $cota->executado,
                'vencido' => ! $cota->executado && $lancamento->data->lessThan($hoje),
                'parcela' => $serie !== null && ! $serie->isRecorrente() ? "{$lancamento->numero_parcela}/{$serie->total_parcelas}" : null,
                'recorrente' => $serie?->isRecorrente() ?? false,
                'compartilhado' => $lancamento->modo_rateio !== null,
                'temAnexo' => $lancamento->anexo_caminho !== null,
                'edicao' => $editavel ? [
                    'tipo' => $lancamento->tipo->value,
                    'descricao' => $lancamento->descricao,
                    'valor' => $lancamento->valor,
                    'data' => $lancamento->data->toDateString(),
                    'categoriaId' => $cota->categoria_id,
                    'carteiraId' => $cota->carteira_id,
                    'observacao' => $lancamento->observacao,
                    'executado' => $cota->executado,
                    'anexoNome' => $lancamento->anexo_nome,
                ] : null,
            ];
        }

        return $secoes;
    }
}
