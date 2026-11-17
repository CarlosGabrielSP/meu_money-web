<?php

namespace App\Actions\Relatorio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;

class DespesasPorCategoria
{
    /**
     * How many categories the donut shows before "Outras" (RN-182).
     */
    public const int NA_ROSCA = 6;

    /**
     * Estimated expenses of the user's shares in the wallet by category in the period: the ranking of
     * every category with its share of the total and its change against the previous period, and the
     * donut with the largest ones plus "Outras" (RN-182). One grouped query for both periods.
     *
     * @return array{ranking: list<array{id: int, nome: string, icone: string, valor: int, percentual: float|null, variacao: float|null}>, rosca: list<array{nome: string, valor: int, outras: bool}>}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $inicioAnterior, CarbonImmutable $inicio, CarbonImmutable $fim): array
    {
        $linhas = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->join('categorias', 'categorias.id', '=', 'cotas.categoria_id')
            ->where('cotas.user_id', $user->id)
            ->where('cotas.carteira_id', $carteira->id)
            ->where('lancamentos.tipo', 'despesa')
            ->where('lancamentos.data', '>=', $inicioAnterior->toDateString())
            ->where('lancamentos.data', '<', $fim->toDateString())
            ->groupBy('categorias.id', 'categorias.nome', 'categorias.icone')
            ->selectRaw('categorias.id, categorias.nome, categorias.icone')
            ->selectRaw('coalesce(sum(case when lancamentos.data >= ? then cotas.valor else 0 end), 0) as valor', [$inicio->toDateString()])
            ->selectRaw('coalesce(sum(case when lancamentos.data < ? then cotas.valor else 0 end), 0) as anterior', [$inicio->toDateString()])
            ->havingRaw('sum(case when lancamentos.data >= ? then cotas.valor else 0 end) > 0', [$inicio->toDateString()])
            ->orderByDesc('valor')
            ->orderBy('categorias.nome')
            ->toBase()
            ->get();

        $total = (int) $linhas->sum(fn (object $linha): int => (int) $linha->valor);

        $ranking = array_values($linhas->map(fn (object $linha): array => [
            'id' => (int) $linha->id,
            'nome' => (string) $linha->nome,
            'icone' => (string) $linha->icone,
            'valor' => (int) $linha->valor,
            'percentual' => Porcentagem::de((int) $linha->valor, $total),
            'variacao' => Porcentagem::variacao((int) $linha->valor, (int) $linha->anterior),
        ])->all());

        return ['ranking' => $ranking, 'rosca' => $this->rosca($ranking)];
    }

    /**
     * The largest categories and, only when there are more, "Outras" with the sum of the rest.
     *
     * @param  list<array{nome: string, valor: int}>  $ranking
     * @return list<array{nome: string, valor: int, outras: bool}>
     */
    private function rosca(array $ranking): array
    {
        $rosca = array_map(
            fn (array $categoria): array => ['nome' => $categoria['nome'], 'valor' => $categoria['valor'], 'outras' => false],
            array_slice($ranking, 0, self::NA_ROSCA),
        );

        if (count($ranking) > self::NA_ROSCA) {
            $rosca[] = [
                'nome' => 'Outras',
                'valor' => array_sum(array_column(array_slice($ranking, self::NA_ROSCA), 'valor')),
                'outras' => true,
            ];
        }

        return $rosca;
    }
}
