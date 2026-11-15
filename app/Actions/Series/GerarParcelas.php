<?php

namespace App\Actions\Series;

use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use Carbon\CarbonImmutable;

class GerarParcelas
{
    /**
     * Create installments numbered from $de to $ate from the series model, each with the author's
     * share and the initial status by date (RN-013, RN-044). Numbers that already exist are skipped,
     * so a rerun never duplicates (RN-045). Notes and attachments are never copied (RN-019).
     *
     * Rows are inserted in bulk; dates use the model storage format so queries compare them alike.
     * Sharing models (RN-084, CA-052.5) are part of phase 7.
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

        foreach (array_chunk($novas, 200, preserve_keys: true) as $lote) {
            Lancamento::query()->insert(array_map(fn (int $numero, array $parcela): array => [
                'user_id' => $serie->user_id,
                'parcelamento_id' => $serie->id,
                'numero_parcela' => $numero,
                'tipo' => $serie->tipo->value,
                'descricao' => $serie->descricao,
                'valor' => $parcela['valor'],
                'data' => $formato->fromDateTime($parcela['data']),
                'created_at' => $formato->fromDateTime($agora),
                'updated_at' => $formato->fromDateTime($agora),
            ], array_keys($lote), $lote));

            $ids = $serie->lancamentos()->whereIn('numero_parcela', array_keys($lote))->pluck('id', 'numero_parcela');

            Cota::query()->insert(array_map(fn (int $numero, array $parcela): array => [
                'lancamento_id' => $ids[$numero],
                'user_id' => $serie->user_id,
                'carteira_id' => $serie->carteira_id,
                'categoria_id' => $serie->categoria_id,
                'valor' => $parcela['valor'],
                'executado' => $parcela['executado'],
                'executado_em' => $parcela['executado'] ? $formato->fromDateTime($agora) : null,
                'created_at' => $formato->fromDateTime($agora),
                'updated_at' => $formato->fromDateTime($agora),
            ], array_keys($lote), $lote));
        }

        $serie->save();
    }
}
