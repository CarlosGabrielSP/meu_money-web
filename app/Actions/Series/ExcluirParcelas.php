<?php

namespace App\Actions\Series;

use App\Actions\Lancamentos\RemoverLancamentos;
use App\Enums\EscopoSerie;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExcluirParcelas
{
    public function __construct(private RemoverLancamentos $removerLancamentos) {}

    /**
     * Delete installments with a scope (RF-054).
     *
     * "Esta" removes only the open installment, never one in a closed card invoice (RN-200). The other scopes remove only pending installments
     * (RN-056); numbers are never reused (RN-057). "Esta e próximas" on a recurring series ends it on
     * the installment date. A series left without installments is removed (CA-054.4); otherwise
     * "todas" ends a recurring series on the last executed installment (RN-139).
     *
     * @throws ValidationException
     */
    public function handle(Lancamento $parcela, EscopoSerie $escopo): void
    {
        /** @var Parcelamento $serie */
        $serie = $parcela->parcelamento;

        $anexos = DB::transaction(function () use ($parcela, $serie, $escopo): array {
            if ($escopo === EscopoSerie::Esta && $parcela->emFaturaFechada(CarbonImmutable::today())) {
                throw ValidationException::withMessages(['lancamento' => 'Compra em fatura fechada não pode ser excluída.']);
            }

            if ($escopo === EscopoSerie::Esta) {
                $anexos = $this->removerLancamentos->handle(new Collection([$parcela]));
            } else {
                $pendentes = $serie->lancamentos()
                    ->when($escopo === EscopoSerie::Proximas, fn ($query) => $query->where('numero_parcela', '>=', $parcela->numero_parcela))
                    ->pendentesDoAutor()
                    ->get();

                $anexos = $this->removerLancamentos->handle($pendentes, somentePendentes: true);
            }

            $ultimaRestante = $serie->lancamentos()->max('data');

            if ($ultimaRestante === null) {
                $serie->delete();
            } elseif ($serie->isRecorrente() && $escopo === EscopoSerie::Proximas) {
                $serie->update(['encerrado_em' => $parcela->data->toDateString()]);
            } elseif ($serie->isRecorrente() && $escopo === EscopoSerie::Todas) {
                $serie->update(['encerrado_em' => substr((string) $ultimaRestante, 0, 10)]);
            }

            return $anexos;
        });

        Storage::disk('local')->delete($anexos);
    }
}
