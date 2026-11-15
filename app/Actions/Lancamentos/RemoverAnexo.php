<?php

namespace App\Actions\Lancamentos;

use App\Models\Lancamento;
use Illuminate\Support\Facades\Storage;

class RemoverAnexo
{
    /**
     * Remove the attachment file and clear its fields (CA-044.5).
     * Only the author changes the attachment, also on shared entries (RN-018).
     */
    public function handle(Lancamento $lancamento): void
    {
        $anexo = $lancamento->anexo_caminho;

        if ($anexo === null) {
            return;
        }

        $lancamento->forceFill(['anexo_caminho' => null, 'anexo_nome' => null])->save();

        Storage::disk('local')->delete($anexo);
    }
}
