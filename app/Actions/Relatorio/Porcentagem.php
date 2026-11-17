<?php

namespace App\Actions\Relatorio;

/**
 * Percentages of the report, with one decimal place (RN-180, RN-182, RN-183).
 */
final class Porcentagem
{
    /**
     * Change against the previous period: (current − previous) ÷ |previous|; null when the previous
     * value is 0 (RN-180).
     */
    public static function variacao(int $atual, int $anterior): ?float
    {
        return $anterior === 0 ? null : round(($atual - $anterior) / abs($anterior) * 100, 1);
    }

    /**
     * Part of a total; null when the total is 0.
     */
    public static function de(int $parte, int $total): ?float
    {
        return $total === 0 ? null : round($parte / $total * 100, 1);
    }
}
