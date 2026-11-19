<?php

namespace App\Actions\Cartoes;

use App\Models\Carteira;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EditarDatasFatura
{
    public function __construct(
        private SalvarCartao $salvarCartao,
        private AtribuirFaturas $atribuirFaturas,
    ) {}

    /**
     * Change closing and due date of an open or future invoice (RN-193, DEC-085). The closing stays
     * after the previous closing and before the next one; the due date is not before the closing. The
     * invoice keeps these dates when the card changes (RN-220, R2), and purchases move between
     * invoices by date. A closed invoice never changes (CA-163.4). The closing is not before today and
     * the due date stays in the invoice month (/code-review da Fase 14).
     *
     * @throws ValidationException
     */
    public function handle(Carteira $cartao, CarbonImmutable $referencia, CarbonImmutable $fechamento, CarbonImmutable $vencimento): void
    {
        DB::transaction(function () use ($cartao, $referencia, $fechamento, $vencimento): void {
            $hoje = CarbonImmutable::today();
            $calendario = FaturasDoCartao::carregar($cartao);
            $fatura = $calendario->daReferencia($referencia);

            if ($fatura->isFechada($hoje)) {
                throw ValidationException::withMessages(['fechamento' => 'Fatura fechada não muda de datas.']);
            }

            $anterior = $calendario->anterior($fatura)->fechamento;
            $proximo = $calendario->proxima($fatura)->fechamento;

            if ($fechamento->lessThanOrEqualTo($anterior) || $fechamento->greaterThanOrEqualTo($proximo)) {
                throw ValidationException::withMessages([
                    'fechamento' => "O fechamento precisa ficar entre {$anterior->format('d/m/Y')} e {$proximo->format('d/m/Y')}.",
                ]);
            }

            if ($fechamento->lessThan($hoje)) {
                throw ValidationException::withMessages(['fechamento' => 'O fechamento não pode ser antes de hoje.']);
            }

            if ($vencimento->lessThan($fechamento)) {
                throw ValidationException::withMessages(['vencimento' => 'O vencimento não pode ser antes do fechamento.']);
            }

            if ($vencimento->greaterThan($fatura->referencia->endOfMonth())) {
                throw ValidationException::withMessages(['vencimento' => 'O vencimento precisa ficar no mês da fatura.']);
            }

            $cotas = $this->salvarCartao->cotasNaoFechadas($cartao, $hoje);

            $fatura->fill(['fechamento' => $fechamento, 'vencimento' => $vencimento]);
            $fatura->datas_editadas = true;
            $fatura->save();

            $this->atribuirFaturas->handle($cotas, permitirFechadas: true);
        });
    }
}
