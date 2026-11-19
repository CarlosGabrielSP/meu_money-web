<?php

namespace App\Actions\Inicio;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\User;
use Carbon\CarbonImmutable;

class GastosDoMes
{
    /**
     * How many categories the main screen shows (RN-174).
     */
    public const int QUANTIDADE = 3;

    /**
     * The categories with the largest estimated expenses of the user in the wallet and month, ties by
     * name, regardless of filters (RN-174). Card purchases count by the due date of their invoice: a
     * card shows the purchases of the invoice due in the month, and a common wallet also those of the
     * invoices it pays (RN-224, RN-226).
     *
     * @return list<array{nome: string, icone: string, valor: int}>
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $mes): array
    {
        return array_values(Cota::query()
            ->naCarteiraPorVencimento($carteira)
            ->join('categorias', 'categorias.id', '=', 'cotas.categoria_id')
            ->where('cotas.user_id', $user->id)
            ->where('lancamentos.tipo', 'despesa')
            ->whereRaw(Cota::DATA_EFETIVA.' >= ?', [$mes->startOfMonth()->toDateString()])
            ->whereRaw(Cota::DATA_EFETIVA.' < ?', [$mes->startOfMonth()->addMonth()->toDateString()])
            ->groupBy('categorias.id', 'categorias.nome', 'categorias.icone')
            ->selectRaw('categorias.nome, categorias.icone, sum(cotas.valor) as valor')
            ->orderByDesc('valor')
            ->orderBy('categorias.nome')
            ->limit(self::QUANTIDADE)
            ->toBase()
            ->get()
            ->map(fn (object $linha): array => [
                'nome' => (string) $linha->nome,
                'icone' => (string) $linha->icone,
                'valor' => (int) $linha->valor,
            ])
            ->all());
    }
}
