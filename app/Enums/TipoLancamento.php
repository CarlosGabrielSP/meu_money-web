<?php

namespace App\Enums;

enum TipoLancamento: string
{
    case Receita = 'receita';
    case Despesa = 'despesa';

    /**
     * Multiplier applied to the share value when summing a balance (RN-020).
     */
    public function sinal(): int
    {
        return match ($this) {
            self::Receita => 1,
            self::Despesa => -1,
        };
    }
}
