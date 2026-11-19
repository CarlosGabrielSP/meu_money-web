<?php

namespace App\Enums;

enum EstadoFatura: string
{
    case Fechada = 'fechada';
    case Aberta = 'aberta';
    case Futura = 'futura';
}
