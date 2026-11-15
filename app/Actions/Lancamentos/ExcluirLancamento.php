<?php

namespace App\Actions\Lancamentos;

use App\Models\Lancamento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExcluirLancamento
{
    /**
     * Delete an entry with its shares and attachment file (CA-043.1).
     *
     * Blocked when a participant already executed their share (RN-086); every share is locked
     * before the check so a concurrent status change waits. Installments follow RF-054 (phase 4).
     *
     * @throws ValidationException
     */
    public function handle(Lancamento $lancamento): void
    {
        if ($lancamento->parcelamento_id !== null) {
            throw ValidationException::withMessages([
                'lancamento' => 'Parcela de série é excluída pelas regras da série, ainda não disponíveis.',
            ]);
        }

        $anexo = $lancamento->anexo_caminho;

        DB::transaction(function () use ($lancamento): void {
            $participanteExecutou = $lancamento->cotas()
                ->lockForUpdate()
                ->get(['id', 'user_id', 'executado'])
                ->contains(fn ($cota) => $cota->user_id !== $lancamento->user_id && $cota->executado);

            if ($participanteExecutou) {
                throw ValidationException::withMessages([
                    'lancamento' => 'Um participante já marcou a cota como executada. O lançamento não pode ser excluído.',
                ]);
            }

            $lancamento->delete();
        });

        if ($anexo !== null) {
            Storage::disk('local')->delete($anexo);
        }
    }
}
