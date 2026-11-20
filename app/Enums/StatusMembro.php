<?php

namespace App\Enums;

/**
 * Status of a member invitation of a delegated wallet (RN-270).
 */
enum StatusMembro: string
{
    case Pendente = 'pendente';
    case Aceito = 'aceito';
    case Recusado = 'recusado';
}
