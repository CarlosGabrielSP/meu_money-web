<?php

namespace App\Actions\Relatorio;

use App\Actions\Inicio\SaldosDasCarteiras;
use App\Models\Carteira;
use App\Models\User;
use Carbon\CarbonImmutable;

class SaldoPorCarteira
{
    public function __construct(private SaldosDasCarteiras $saldosDasCarteiras) {}

    /**
     * Every wallet of the user with the current and the estimated accumulated balance until the last
     * day of the current month (RN-186). Two queries whatever the number of wallets (RNF-030).
     *
     * @return list<array{id: int, nome: string, cor: string, icone: string, atual: int, estimado: int}>
     */
    public function handle(User $user, CarbonImmutable $hoje): array
    {
        $saldos = $this->saldosDasCarteiras->atuaisEEstimados($user, $hoje);

        return array_values($user->carteiras()
            ->orderByDesc('padrao')
            ->orderBy('nome')
            ->get(['id', 'nome', 'cor', 'icone'])
            ->map(fn (Carteira $carteira): array => [
                'id' => $carteira->id,
                'nome' => $carteira->nome,
                'cor' => $carteira->cor->value,
                'icone' => $carteira->icone->value,
                'atual' => $saldos[$carteira->id]['atual'] ?? 0,
                'estimado' => $saldos[$carteira->id]['estimado'] ?? 0,
            ])
            ->all());
    }
}
