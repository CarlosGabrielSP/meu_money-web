<?php

namespace App\Enums;

enum StatusContato: string
{
    case Pendente = 'pendente';
    case Aceito = 'aceito';
    case Recusado = 'recusado';
}
