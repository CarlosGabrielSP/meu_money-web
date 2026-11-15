<?php

namespace App\Actions\Contatos;

use App\Enums\StatusContato;
use App\Models\Contato;

class ResponderConvite
{
    /**
     * Accept or refuse a pending invitation; the requester is not told about a refusal (RF-061).
     */
    public function handle(Contato $contato, bool $aceitar): void
    {
        $contato->update([
            'status' => $aceitar ? StatusContato::Aceito : StatusContato::Recusado,
            'respondido_em' => now(),
        ]);
    }
}
