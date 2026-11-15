<?php

namespace App\Enums;

enum ModoRateio: string
{
    case Iguais = 'iguais';
    case Percentual = 'percentual';
    case Fixo = 'fixo';
}
