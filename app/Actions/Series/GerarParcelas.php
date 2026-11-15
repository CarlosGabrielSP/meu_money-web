<?php

namespace App\Actions\Series;

use App\Actions\Rateio\CalcularCotas;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Grupo;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\ParcelamentoCota;
use Carbon\CarbonImmutable;

class GerarParcelas
{
    public function __construct(private CalcularCotas $calcularCotas) {}

    /**
     * Create installments numbered from $de to $ate from the series model, each with the author's
     * share and the initial status by date (RN-013, RN-044). Numbers that already exist are skipped,
     * so a rerun never duplicates (RN-045). Notes and attachments are never copied (RN-019).
     *
     * A shared series replicates its model split on each installment (RN-084, CA-052.5): the
     * author's share uses the series wallet and category, participants get their default wallet and
     * the category by RN-082, not seen yet (RN-114). Shares are born in the group of the model:
     * the series group for the author, the model share group for a participant (RN-126, RN-148).
     *
     * Rows are inserted in bulk; dates use the model storage format so queries compare them alike.
     *
     * @param  array<int, int>  $valores  amount per installment number, overriding the model amount
     */
    public function handle(Parcelamento $serie, int $de, int $ate, array $valores = []): void
    {
        if ($ate < $de) {
            return;
        }

        $existentes = $serie->lancamentos()->whereBetween('numero_parcela', [$de, $ate])->pluck('numero_parcela')->all();
        $agora = CarbonImmutable::now();
        $formato = new Lancamento;
        $novas = [];

        for ($numero = $de; $numero <= $ate; $numero++) {
            $data = $serie->dataDaParcela($numero);

            if ($data->greaterThan($serie->gerado_ate)) {
                $serie->gerado_ate = $data;
            }

            if (in_array($numero, $existentes, true)) {
                continue;
            }

            $novas[$numero] = [
                'data' => $data,
                'valor' => $valores[$numero] ?? $serie->valor_parcela,
                'executado' => $serie->tipo->executadoPorPadrao($data, $agora),
            ];
        }

        $cotasDoModelo = $serie->modo_rateio === null ? collect() : $serie->cotas()->orderBy('id')->get(['user_id', 'percentual', 'valor', 'grupo_id']);
        $modelo = array_values($cotasDoModelo
            ->map(fn (ParcelamentoCota $cota): array => ['user_id' => $cota->user_id, 'percentual' => $cota->percentual, 'valor' => $cota->valor])
            ->all());
        $carteiras = Carteira::query()->whereIn('user_id', array_column($modelo, 'user_id'))->where('padrao', true)->pluck('id', 'user_id');
        $grupos = $this->gruposDosParticipantes($serie, $cotasDoModelo->pluck('grupo_id', 'user_id')->all(), $carteiras->all());
        $categoriaParticipante = $modelo === [] ? null : $this->categoriaDoParticipante($serie);

        foreach (array_chunk($novas, 200, preserve_keys: true) as $lote) {
            Lancamento::query()->insert(array_map(fn (int $numero, array $parcela): array => [
                'user_id' => $serie->user_id,
                'carteira_id' => $serie->carteira_id,
                'parcelamento_id' => $serie->id,
                'numero_parcela' => $numero,
                'tipo' => $serie->tipo->value,
                'descricao' => $serie->descricao,
                'valor' => $parcela['valor'],
                'modo_rateio' => $serie->modo_rateio?->value,
                'data' => $formato->fromDateTime($parcela['data']),
                'created_at' => $formato->fromDateTime($agora),
                'updated_at' => $formato->fromDateTime($agora),
            ], array_keys($lote), $lote));

            $ids = $serie->lancamentos()->whereIn('numero_parcela', array_keys($lote))->pluck('id', 'numero_parcela');

            $cotas = [];

            foreach ($lote as $numero => $parcela) {
                $divisao = $modelo === []
                    ? [$serie->user_id => ['valor' => $parcela['valor'], 'percentual' => null]]
                    : $this->calcularCotas->handle($parcela['valor'], $serie->modo_rateio, $modelo, $serie->user_id);

                foreach ($divisao as $userId => $cota) {
                    $doAutor = $userId === $serie->user_id;

                    $cotas[] = [
                        'lancamento_id' => $ids[$numero],
                        'user_id' => $userId,
                        'carteira_id' => $doAutor ? $serie->carteira_id : $carteiras[$userId],
                        'categoria_id' => $doAutor ? $serie->categoria_id : $categoriaParticipante,
                        'grupo_id' => $doAutor ? $serie->grupo_id : ($grupos[$userId] ?? null),
                        'valor' => $cota['valor'],
                        'percentual' => $cota['percentual'],
                        'executado' => $parcela['executado'],
                        'executado_em' => $parcela['executado'] ? $formato->fromDateTime($agora) : null,
                        'vista_em' => $doAutor ? $formato->fromDateTime($agora) : null,
                        'created_at' => $formato->fromDateTime($agora),
                        'updated_at' => $formato->fromDateTime($agora),
                    ];
                }
            }

            Cota::query()->insert($cotas);
        }

        $serie->save();
    }

    /**
     * Group of each participant's new share: the one on their model share, only when it belongs to
     * the wallet the share is born in (RN-126, RN-148).
     *
     * @param  array<int, int|null>  $porUsuario  group id of the model share by user id
     * @param  array<int, int>  $carteiras  default wallet id by user id
     * @return array<int, int>
     */
    private function gruposDosParticipantes(Parcelamento $serie, array $porUsuario, array $carteiras): array
    {
        $porUsuario = array_filter($porUsuario, fn (?int $grupoId, int $userId): bool => $grupoId !== null && $userId !== $serie->user_id, ARRAY_FILTER_USE_BOTH);

        if ($porUsuario === []) {
            return [];
        }

        $carteiraDoGrupo = Grupo::query()->whereKey(array_values($porUsuario))->pluck('carteira_id', 'id');

        return array_filter($porUsuario, fn (int $grupoId, int $userId): bool => ($carteiraDoGrupo[$grupoId] ?? null) === ($carteiras[$userId] ?? null), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Participants get the series category when it is global, else "Outros" (RN-082).
     */
    private function categoriaDoParticipante(Parcelamento $serie): int
    {
        $categoria = Categoria::query()->whereKey($serie->categoria_id)->firstOrFail();

        return $categoria->isGlobal() ? $categoria->id : Categoria::outros()->id;
    }
}
