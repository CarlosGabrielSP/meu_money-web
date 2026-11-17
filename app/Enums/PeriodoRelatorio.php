<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

/**
 * Periods of the report (RN-179): this month, the last 3, 6 or 12 months ending in the current month,
 * and the current year.
 */
enum PeriodoRelatorio: string
{
    case Mes = 'mes';
    case TresMeses = '3m';
    case SeisMeses = '6m';
    case DozeMeses = '12m';
    case Ano = 'ano';

    public const self PADRAO = self::SeisMeses;

    /**
     * First day of every month of the period, in order.
     *
     * @return non-empty-list<CarbonImmutable>
     */
    public function meses(CarbonImmutable $hoje): array
    {
        $inicio = $this->inicio($hoje);

        return array_map(
            fn (int $indice): CarbonImmutable => $inicio->addMonthsNoOverflow($indice),
            range(0, $this->quantidadeDeMeses() - 1),
        );
    }

    /**
     * First day of the period.
     */
    public function inicio(CarbonImmutable $hoje): CarbonImmutable
    {
        return $this === self::Ano
            ? $hoje->startOfYear()
            : $hoje->startOfMonth()->subMonthsNoOverflow($this->quantidadeDeMeses() - 1);
    }

    /**
     * First day after the period.
     */
    public function fim(CarbonImmutable $hoje): CarbonImmutable
    {
        return $this->inicio($hoje)->addMonthsNoOverflow($this->quantidadeDeMeses());
    }

    /**
     * First day of the previous period: the same number of months right before; the previous year for
     * the current year. The previous period ends where this one starts.
     */
    public function inicioAnterior(CarbonImmutable $hoje): CarbonImmutable
    {
        return $this->inicio($hoje)->subMonthsNoOverflow($this->quantidadeDeMeses());
    }

    private function quantidadeDeMeses(): int
    {
        return match ($this) {
            self::Mes => 1,
            self::TresMeses => 3,
            self::SeisMeses => 6,
            self::DozeMeses, self::Ano => 12,
        };
    }
}
