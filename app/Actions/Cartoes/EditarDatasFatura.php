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
     * invoices by date. A closed invoice never changes (CA-163.4). The closing is not before today
     * (/code-review da Fase 14). Both dates stay in the months of the competence window, and the due
     * date stays between the due dates of the previous and the next invoice (RN-193).
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

            $faturaAnterior = $calendario->anterior($fatura);
            $faturaProxima = $calendario->proxima($fatura);
            $anterior = $faturaAnterior->fechamento;
            $proximo = $faturaProxima->fechamento;

            if ($fechamento->lessThanOrEqualTo($anterior) || $fechamento->greaterThanOrEqualTo($proximo)) {
                throw ValidationException::withMessages([
                    'fechamento' => "O fechamento precisa ficar entre {$anterior->format('d/m/Y')} e {$proximo->format('d/m/Y')}.",
                ]);
            }

            if ($fechamento->lessThan($hoje)) {
                throw ValidationException::withMessages(['fechamento' => 'O fechamento não pode ser antes de hoje.']);
            }

            [$inicio, $fim] = $calendario->cartao->janelaDeDatas($fatura->referencia);
            $meses = $inicio->translatedFormat('F').' ou '.$fim->translatedFormat('F');

            foreach (['fechamento' => $fechamento, 'vencimento' => $vencimento] as $campo => $data) {
                if ($data->lessThan($inicio) || $data->greaterThan($fim)) {
                    throw ValidationException::withMessages([$campo => "O {$campo} precisa ficar em {$meses}."]);
                }
            }

            if ($vencimento->lessThan($fechamento)) {
                throw ValidationException::withMessages(['vencimento' => 'O vencimento não pode ser antes do fechamento.']);
            }

            $vencimentoAnterior = $faturaAnterior->vencimento;
            $vencimentoProximo = $faturaProxima->vencimento;

            if ($vencimento->lessThanOrEqualTo($vencimentoAnterior) || $vencimento->greaterThanOrEqualTo($vencimentoProximo)) {
                throw ValidationException::withMessages([
                    'vencimento' => "O vencimento precisa ficar entre {$vencimentoAnterior->format('d/m/Y')} e {$vencimentoProximo->format('d/m/Y')}.",
                ]);
            }

            $cotas = $this->salvarCartao->cotasNaoFechadas($cartao, $hoje);

            $fatura->fill(['fechamento' => $fechamento, 'vencimento' => $vencimento]);
            $fatura->datas_editadas = true;
            $fatura->save();

            $this->atribuirFaturas->handle($cotas, permitirFechadas: true);
        });
    }
}
