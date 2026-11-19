<?php

namespace App\Enums;

/**
 * Month that names a card invoice and places it on the card screen (RN-253, DEC-089).
 */
enum CompetenciaFatura: string
{
    case Fechamento = 'fechamento';
    case Vencimento = 'vencimento';
}
