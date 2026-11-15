<?php

namespace App\Actions\Series;

use App\Actions\Lancamentos\RemoverLancamentos;
use App\Models\Parcelamento;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class EncerrarRecorrente
{
    public function __construct(private RemoverLancamentos $removerLancamentos) {}

    /**
     * End a recurring series on a date: pending installments after it are removed and executed ones
     * stay (RN-046, CA-055.1).
     *
     * @throws ValidationException
     */
    public function handle(Parcelamento $serie, CarbonImmutable $data): void
    {
        $anexos = DB::transaction(function () use ($serie, $data): array {
            $serie->update(['encerrado_em' => $data->toDateString()]);

            $pendentes = $serie->lancamentos()
                ->where('data', '>=', $data->addDay()->toDateString())
                ->pendentesDoAutor()
                ->get();

            return $this->removerLancamentos->handle($pendentes, somentePendentes: true);
        });

        Storage::disk('local')->delete($anexos);
    }
}
