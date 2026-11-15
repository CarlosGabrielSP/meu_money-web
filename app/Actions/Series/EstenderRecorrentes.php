<?php

namespace App\Actions\Series;

use App\Models\Parcelamento;
use Illuminate\Support\Facades\DB;

class EstenderRecorrentes
{
    public function __construct(private GerarParcelas $gerarParcelas) {}

    /**
     * Keep every recurring series generated 12 months ahead (RN-045, RF-052), never after its end
     * date (RN-046): a series ended in the future keeps generating until that date.
     *
     * Only numbers after `gerado_ate` are generated, so a deleted installment is never recreated
     * (RN-057) and running twice generates nothing new (CA-052.2).
     *
     * @return int number of series extended
     */
    public function handle(): int
    {
        $horizonte = Parcelamento::horizonteRecorrente();
        $estendidas = 0;

        Parcelamento::query()
            ->whereNull('total_parcelas')
            ->where(fn ($query) => $query->whereNull('encerrado_em')->orWhereColumn('encerrado_em', '>', 'gerado_ate'))
            ->where('gerado_ate', '<', $horizonte->toDateString())
            ->chunkById(100, function ($series) use ($horizonte, &$estendidas): void {
                foreach ($series as $serie) {
                    $limite = $serie->encerrado_em !== null && $serie->encerrado_em->lessThan($horizonte) ? $serie->encerrado_em : $horizonte;
                    $de = $serie->numeroDaData($serie->gerado_ate) + 1;
                    $ate = $serie->ultimoNumeroAte($limite);

                    if ($ate >= $de) {
                        DB::transaction(fn () => $this->gerarParcelas->handle($serie, $de, $ate));
                        $estendidas++;
                    }
                }
            });

        return $estendidas;
    }
}
