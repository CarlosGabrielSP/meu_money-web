<?php

namespace App\Actions\Lancamentos;

use App\Models\Lancamento;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class RemoverAnexo
{
    /**
     * Remove the attachment file and clear its fields (CA-044.5).
     *
     * Shared entries are refused like every other change to them until phase 7 (RN-018, RN-079).
     *
     * @throws ValidationException
     */
    public function handle(Lancamento $lancamento): void
    {
        if ($lancamento->modo_rateio !== null) {
            throw ValidationException::withMessages([
                'lancamento' => 'A edição de lançamento compartilhado ainda não está disponível.',
            ]);
        }

        $anexo = $lancamento->anexo_caminho;

        if ($anexo === null) {
            return;
        }

        $lancamento->forceFill(['anexo_caminho' => null, 'anexo_nome' => null])->save();

        Storage::disk('local')->delete($anexo);
    }
}
