<?php

namespace App\Actions\Series;

use App\Actions\Rateio\AplicarRateio;
use App\Enums\ModoRateio;
use App\Enums\Periodicidade;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CriarSerie
{
    public function __construct(
        private GerarParcelas $gerarParcelas,
        private AplicarRateio $aplicarRateio,
    ) {}

    /**
     * Create an installment or recurring series and generate its installments (RF-050, RF-051).
     *
     * An existing simple entry becomes installment 1 (RN-059, RN-140); a new series puts the typed
     * note on installment 1 only (RN-019).
     *
     * A split becomes the model of the series and is replicated on every installment (RN-084,
     * CA-057.2). A converted entry keeps RN-089: a share a participant executed cannot change; a new
     * series skips the check, since its shares may be born executed (CA-070.12).
     *
     * @param  array{tipo: TipoLancamento, descricao: string, valor: int, data: string, categoria_id: int, carteira_id: int, rateio?: array{modo: ModoRateio, participantes: list<array{user_id: int, percentual: string|null, valor: int|null}>}|null}  $dados
     * @param  array{recorrente: bool, periodicidade: Periodicidade, total_parcelas: int|null, valor_total: bool}  $repeticao
     */
    public function handle(User $autor, array $dados, array $repeticao, ?Lancamento $primeira = null): Parcelamento
    {
        return DB::transaction(function () use ($autor, $dados, $repeticao, $primeira): Parcelamento {
            $total = $repeticao['recorrente'] ? null : $repeticao['total_parcelas'];
            [$valorParcela, $valorPrimeira] = $this->valores($dados['valor'], $total, $repeticao['valor_total']);
            $data = CarbonImmutable::parse($dados['data']);

            $serie = new Parcelamento([
                'carteira_id' => $dados['carteira_id'],
                'categoria_id' => $dados['categoria_id'],
                'descricao' => $dados['descricao'],
                'tipo' => $dados['tipo'],
                'valor_parcela' => $valorParcela,
                'total_parcelas' => $total,
                'periodicidade' => $repeticao['periodicidade'],
                'data_inicio' => $data->toDateString(),
                'dia_vencimento' => $data->day,
                'gerado_ate' => $data->toDateString(),
            ]);
            $serie->user_id = $autor->id;
            $rateio = $dados['rateio'] ?? null;
            $serie->modo_rateio = $rateio['modo'] ?? null;
            $serie->save();

            foreach ($rateio['participantes'] ?? [] as $participante) {
                $serie->cotas()->create($participante);
            }

            if ($primeira !== null) {
                $primeira->forceFill([
                    'parcelamento_id' => $serie->id,
                    'numero_parcela' => 1,
                    'valor' => $valorPrimeira,
                ])->save();
                $this->aplicarRateio->handle(
                    $primeira,
                    $rateio['modo'] ?? null,
                    $rateio['participantes'] ?? [],
                    ['carteira_id' => $dados['carteira_id'], 'categoria_id' => $dados['categoria_id'], 'executado' => null],
                    verificarExecutadas: ! $primeira->wasRecentlyCreated,
                );
            }

            $ultima = $total ?? max(1, $serie->ultimoNumeroAte(Parcelamento::horizonteRecorrente()));

            $this->gerarParcelas->handle($serie, 1, $ultima, [1 => $valorPrimeira]);

            return $serie;
        });
    }

    /**
     * Amount of each installment and of the first one. A total is split with the remaining cents on
     * the first installment (RN-042, RN-051); recurring series take only the installment amount (RN-043).
     *
     * @return array{int, int}
     */
    private function valores(int $valor, ?int $total, bool $valorTotal): array
    {
        if ($total === null || ! $valorTotal) {
            return [$valor, $valor];
        }

        $parcela = intdiv($valor, $total);

        return [$parcela, $parcela + $valor - $parcela * $total];
    }
}
