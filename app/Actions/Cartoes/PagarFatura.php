<?php

namespace App\Actions\Cartoes;

use App\Models\Carteira;
use App\Models\PagamentoFatura;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class PagarFatura
{
    /**
     * Pay the invoice due in the month, creating it when needed (RN-195, RN-222). Any amount above
     * zero is accepted, also above what is left or ahead of time; the excess becomes a credit. The
     * payment leaves the wallet linked to the invoice, or no wallet for an independent card (RN-196,
     * RN-197, RN-229).
     */
    public function handle(Carteira $cartao, CarbonImmutable $referencia, int $valor, string $data): PagamentoFatura
    {
        return DB::transaction(function () use ($cartao, $referencia, $valor, $data): PagamentoFatura {
            $calendario = FaturasDoCartao::carregar($cartao);
            $fatura = $calendario->salvar($calendario->daReferencia($referencia));

            return $fatura->pagamentos()->create([
                'carteira_id' => $fatura->carteira_vinculada_id,
                'valor' => $valor,
                'data' => $data,
            ]);
        });
    }
}
