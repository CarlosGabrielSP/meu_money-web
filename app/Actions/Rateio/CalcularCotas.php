<?php

namespace App\Actions\Rateio;

use App\Enums\ModoRateio;

class CalcularCotas
{
    /**
     * Amount of each share of a split (RN-072 to RN-075).
     *
     * Equal parts divide the amount; percentages are rounded down; fixed amounts are taken as typed.
     * The remaining cents, positive or negative, go to the author's share or, when the author has no
     * share, to the first participant included (RN-073, RN-074, RN-143).
     *
     * @param  list<array{user_id: int, percentual: string|null, valor: int|null}>  $participantes
     * @return array<int, array{valor: int, percentual: string|null}> keyed by user id, in participant order
     */
    public function handle(int $total, ModoRateio $modo, array $participantes, int $autorId): array
    {
        $cotas = [];
        $quantidade = count($participantes);

        foreach ($participantes as $participante) {
            $cotas[$participante['user_id']] = [
                'valor' => match ($modo) {
                    ModoRateio::Iguais => intdiv($total, max(1, $quantidade)),
                    ModoRateio::Percentual => intdiv($total * $this->centesimos($participante['percentual']), 10_000),
                    ModoRateio::Fixo => (int) $participante['valor'],
                },
                'percentual' => $modo === ModoRateio::Percentual ? $participante['percentual'] : null,
            ];
        }

        if ($cotas === []) {
            return [];
        }

        $sobra = $total - array_sum(array_column($cotas, 'valor'));
        $recebe = array_key_exists($autorId, $cotas) ? $autorId : array_key_first($cotas);
        $cotas[$recebe] = ['valor' => $cotas[$recebe]['valor'] + $sobra, 'percentual' => $cotas[$recebe]['percentual']];

        return $cotas;
    }

    /**
     * Percentage as hundredths of a percent (33.33% = 3333), avoiding float rounding.
     */
    public function centesimos(?string $percentual): int
    {
        return (int) round(((float) $percentual) * 100);
    }
}
