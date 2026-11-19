<?php

namespace App\Enums;

/**
 * Who settled a contact's part on the author's invoice (RN-202, RN-240, RN-241).
 */
enum OrigemQuitacao: string
{
    case Autor = 'autor';
    case Contato = 'contato';
}
