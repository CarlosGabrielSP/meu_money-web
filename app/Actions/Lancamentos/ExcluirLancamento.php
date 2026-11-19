<?php

namespace App\Actions\Lancamentos;

use App\Models\Lancamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExcluirLancamento
{
    public function __construct(private RemoverLancamentos $removerLancamentos) {}

    /**
     * Delete an entry with its shares and attachment file (CA-043.1).
     *
     * Blocked when a participant already executed their share (RN-086), and for a purchase in a
     * closed card invoice (RN-200). Installments follow
     * RF-054 with a scope.
     *
     * @throws ValidationException
     */
    public function handle(Lancamento $lancamento): void
    {
        if ($lancamento->parcelamento_id !== null) {
            throw ValidationException::withMessages(['escopo' => 'Escolha quais parcelas excluir.']);
        }

        if ($lancamento->emFaturaFechada(CarbonImmutable::today())) {
            throw ValidationException::withMessages(['lancamento' => 'Compra em fatura fechada não pode ser excluída.']);
        }

        $anexos = DB::transaction(fn (): array => $this->removerLancamentos->handle(new Collection([$lancamento])));

        Storage::disk('local')->delete($anexos);
    }
}
