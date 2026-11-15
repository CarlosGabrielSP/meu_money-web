<?php

namespace App\Enums;

enum Periodicidade: string
{
    case Mensal = 'mensal';
    case Bimestral = 'bimestral';
    case Trimestral = 'trimestral';
    case Semestral = 'semestral';
    case Anual = 'anual';

    /**
     * Number of months between two consecutive installments (RN-041).
     */
    public function meses(): int
    {
        return match ($this) {
            self::Mensal => 1,
            self::Bimestral => 2,
            self::Trimestral => 3,
            self::Semestral => 6,
            self::Anual => 12,
        };
    }
}
