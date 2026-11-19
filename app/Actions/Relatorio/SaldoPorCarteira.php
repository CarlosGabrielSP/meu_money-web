<?php

namespace App\Actions\Relatorio;

use App\Actions\Cartoes\DividaDosCartoes;
use App\Actions\Inicio\SaldosDasCarteiras;
use App\Models\Carteira;
use App\Models\User;
use Carbon\CarbonImmutable;

class SaldoPorCarteira
{
    public function __construct(
        private SaldosDasCarteiras $saldosDasCarteiras,
        private DividaDosCartoes $dividaDosCartoes,
    ) {}

    /**
     * Every common wallet of the user with the current and the estimated accumulated balance until the
     * last day of the current month (RN-186, RN-196), and apart, outside that sum, every card with its
     * debt: current until today and estimated until the last day of the current month (RN-227, R9). A
     * constant number of queries whatever the number of wallets (RNF-030).
     *
     * @return array{carteiras: list<array{id: int, nome: string, cor: string, icone: string, atual: int, estimado: int}>, cartoes: list<array{id: int, nome: string, cor: string, icone: string, atual: int, estimado: int}>}
     */
    public function handle(User $user, CarbonImmutable $hoje): array
    {
        $saldos = $this->saldosDasCarteiras->atuaisEEstimados($user, $hoje);
        $dividas = $this->dividaDosCartoes->handle($user, [$hoje, $hoje->endOfMonth()]);
        $carteiras = $user->carteiras()
            ->orderByDesc('padrao')
            ->orderBy('nome')
            ->get(['id', 'nome', 'tipo', 'cor', 'icone']);

        $linha = fn (Carteira $carteira, int $atual, int $estimado): array => [
            'id' => $carteira->id,
            'nome' => $carteira->nome,
            'cor' => $carteira->cor->value,
            'icone' => $carteira->icone->value,
            'atual' => $atual,
            'estimado' => $estimado,
        ];

        return [
            'carteiras' => array_values($carteiras
                ->reject(fn (Carteira $carteira): bool => $carteira->isCartao())
                ->map(fn (Carteira $carteira): array => $linha($carteira, $saldos[$carteira->id]['atual'] ?? 0, $saldos[$carteira->id]['estimado'] ?? 0))
                ->all()),
            'cartoes' => array_values($carteiras
                ->filter(fn (Carteira $carteira): bool => $carteira->isCartao())
                ->map(fn (Carteira $carteira): array => $linha($carteira, $dividas[$carteira->id][0] ?? 0, $dividas[$carteira->id][1] ?? 0))
                ->all()),
        ];
    }
}
