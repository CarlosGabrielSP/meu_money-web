<?php

namespace App\Actions\Relatorio;

use App\Models\Carteira;
use App\Models\User;
use Carbon\CarbonImmutable;

class Previsao
{
    /**
     * How many months after the current one the forecast shows (RN-184).
     */
    public const int MESES = 6;

    public function __construct(private EvolucaoMensal $evolucaoMensal) {}

    /**
     * Estimated result and accumulated balance of the months after the current one, only from the
     * existing entries, and the first month with a negative balance. Independent of the period (RN-184).
     * A card shows its debt and has no negative month to highlight (RN-227, R7).
     *
     * @return array{meses: list<array{mes: string, resultado: int, acumulado: int}>, primeiroNegativo: string|null}
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $hoje): array
    {
        $meses = array_map(
            fn (int $indice): CarbonImmutable => $hoje->startOfMonth()->addMonthsNoOverflow($indice),
            range(1, self::MESES),
        );

        $previsao = array_map(fn (array $mes): array => [
            'mes' => $mes['mes'],
            'resultado' => $mes['receitas'] - $mes['despesas'],
            'acumulado' => $mes['acumulado'],
        ], $this->evolucaoMensal->handle($user, $carteira, $meses));

        $negativo = $carteira->isCartao() ? null : array_find($previsao, fn (array $mes): bool => $mes['acumulado'] < 0);

        return [
            'meses' => $previsao,
            'primeiroNegativo' => $negativo['mes'] ?? null,
        ];
    }
}
