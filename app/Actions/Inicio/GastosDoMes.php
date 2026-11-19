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
     * name, regardless of filters (RN-174). A card shows the purchases of the invoice on screen, the
     * same as its list, whatever its due date (RN-224); a common wallet also counts the purchases of
     * the invoices it pays by their due date (RN-226).
     *
     * @param  int|null  $faturaId  invoice on screen, for a card; null when it was never created
     * @return list<array{nome: string, icone: string, valor: int}>
     */
    public function handle(User $user, Carteira $carteira, CarbonImmutable $mes, ?int $faturaId = null): array
    {
        return array_values(Cota::query()
            ->naCarteiraPorVencimento($carteira)
            ->join('categorias', 'categorias.id', '=', 'cotas.categoria_id')
            ->where('cotas.user_id', $user->id)
            ->where('lancamentos.tipo', 'despesa')
            ->when(
                $carteira->isCartao(),
                fn ($query) => $query->where('cotas.fatura_id', $faturaId ?? 0),
                fn ($query) => $query
                    ->whereRaw(Cota::DATA_EFETIVA.' >= ?', [$mes->startOfMonth()->toDateString()])
                    ->whereRaw(Cota::DATA_EFETIVA.' < ?', [$mes->startOfMonth()->addMonth()->toDateString()]),
            )
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
