<?php

namespace App\Enums;

enum TipoCategoria: string
{
    case Receita = 'receita';
    case Despesa = 'despesa';
    case Ambos = 'ambos';

    /**
     * Whether a category of this type may classify an entry of the given type (RN-033).
     */
    public function aceita(TipoLancamento $tipoLancamento): bool
    {
        return $this === self::Ambos || $this->value === $tipoLancamento->value;
    }
}
