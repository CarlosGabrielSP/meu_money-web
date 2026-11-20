<?php

namespace App\Enums;

use Carbon\CarbonInterface;

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

    /**
     * How the interface names an executed share in messages: "paga" (expense) or "recebida"
     * (income); "executada" stays a technical term (DEC-083, DEC-093).
     */
    public function executadaNaInterface(): string
    {
        return match ($this) {
            self::Receita => 'recebida',
            self::Despesa => 'paga',
        };
    }

    /**
     * Initial status of a share when the user does not choose one (RN-013, DEC-025):
     * past dates are executed, future dates pending, and today only income is executed.
     */
    public function executadoPorPadrao(CarbonInterface $data, CarbonInterface $hoje): bool
    {
        if ($data->isSameDay($hoje)) {
            return $this === self::Receita;
        }

        return $data->lessThan($hoje->copy()->startOfDay());
    }
}
