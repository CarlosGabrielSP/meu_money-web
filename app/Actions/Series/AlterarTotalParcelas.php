<?php

namespace App\Actions\Series;

use App\Actions\Lancamentos\RemoverLancamentos;
use App\Models\Cota;
use App\Models\Parcelamento;
use App\Models\QuitacaoContato;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AlterarTotalParcelas
{
    public function __construct(
        private GerarParcelas $gerarParcelas,
        private RemoverLancamentos $removerLancamentos,
    ) {}

    /**
     * Change the number of installments (RN-058): more installments are added at the end with the
     * model amount; fewer removes pending installments from the end, never below the highest
     * executed number (CA-056.1 to CA-056.3). An author installment in a closed card invoice counts as
     * executed (RN-200). Shares are locked before the check. An installment to remove with a settled
     * contact part fails on the total, with the message of RN-258 (RN-250).
     *
     * @throws ValidationException
     */
    public function handle(Parcelamento $serie, int $total): void
    {
        $anexos = DB::transaction(function () use ($serie, $total): array {
            $atual = (int) $serie->total_parcelas;

            $executadas = Cota::query()
                ->whereIn('lancamento_id', $serie->lancamentos()->select('id'))
                ->where('user_id', $serie->user_id)
                ->lockForUpdate()
                ->with('fatura:id,fechamento')
                ->get(['lancamento_id', 'fatura_id', 'executado'])
                ->filter(fn (Cota $cota): bool => $cota->isTravada(CarbonImmutable::today()))
                ->pluck('lancamento_id');

            $maiorExecutada = (int) $serie->lancamentos()->whereKey($executadas)->max('numero_parcela');

            if ($total < $maiorExecutada) {
                throw ValidationException::withMessages([
                    'total_parcelas' => "A parcela {$maiorExecutada} já foi {$serie->tipo->executadaNaInterface()}. O total não pode ficar abaixo dela.",
                ]);
            }

            $quitadas = Cota::query()
                ->whereIn('lancamento_id', $serie->lancamentos()->where('numero_parcela', '>', $total)->select('id'))
                ->where('user_id', '!=', $serie->user_id)
                ->quitadas()
                ->with('user:id,name')
                ->get();

            if ($quitadas->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'total_parcelas' => QuitacaoContato::mensagemDeTrava($quitadas->map(fn (Cota $cota): string => $cota->user->name), excluir: true),
                ]);
            }

            $anexos = $this->removerLancamentos->handle($serie->lancamentos()->where('numero_parcela', '>', $total)->get());

            $serie->total_parcelas = $total;

            if ($total < $atual) {
                $serie->gerado_ate = $serie->dataDaParcela($total);
            }

            $serie->save();

            $this->gerarParcelas->handle($serie, $atual + 1, $total);

            return $anexos;
        });

        Storage::disk('local')->delete($anexos);
    }
}
