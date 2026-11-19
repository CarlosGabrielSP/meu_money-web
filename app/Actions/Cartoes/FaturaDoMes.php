<?php

namespace App\Actions\Cartoes;

use App\Models\Carteira;
use App\Models\Fatura;
use App\Models\PagamentoFatura;
use App\Models\User;
use Carbon\CarbonImmutable;

class FaturaDoMes
{
    /**
     * Invoices of the last card loaded, shared by mesDaAberta and handle in one request.
     */
    private ?FaturasDoCartao $calendario = null;

    public function __construct(private CalcularFaturas $calcularFaturas) {}

    /**
     * The invoice card of the card screen for the invoice due in the month (RN-198, RN-221, RN-223):
     * to pay, state, period, closing, due date, previous balance, purchases, income and payments, in a
     * constant number of queries (RNF-030). A month without a stored invoice shows the default dates,
     * to pay = previous balance and no payment (CA-169.7). The payment leaves the wallet linked to the
     * invoice; null is an independent card (RN-196, RN-197, RN-229).
     *
     * @param  array<int, list<array{fatura: Fatura, compras: int, receitas: int, pagamentos: int, saldoAnterior: int, aPagar: int}>>|null  $faturas  CalcularFaturas, to reuse in the request
     * @return array{id: int|null, referencia: string, estado: string, inicio: string, fechamento: string, vencimento: string, saldoAnterior: int, compras: int, receitas: int, pagamentos: int, aPagar: int, editavel: bool, limites: array{fechamentoAnterior: string, fechamentoProximo: string}, pagaPor: string|null, pagamentosLista: list<array{id: int, data: string, valor: int, origem: string|null}>}
     */
    public function handle(User $user, Carteira $cartao, CarbonImmutable $mes, CarbonImmutable $hoje, ?array $faturas = null): array
    {
        $calendario = $this->calendario($cartao);
        $fatura = $calendario->daReferencia($mes);
        $linhas = ($faturas ?? $this->calcularFaturas->handle($user, [$cartao->id]))[$cartao->id] ?? [];
        $linha = collect($linhas)->first(fn (array $linha): bool => $linha['fatura']->id === $fatura->id);
        $aPagar = $linha['aPagar'] ?? CalcularFaturas::aPagarNoMes($linhas, $mes);

        return [
            'id' => $fatura->exists ? $fatura->id : null,
            'referencia' => $fatura->referencia->format('Y-m'),
            'estado' => $calendario->estado($fatura, $hoje)->value,
            'inicio' => $calendario->inicioDoPeriodo($fatura)->toDateString(),
            'fechamento' => $fatura->fechamento->toDateString(),
            'vencimento' => $fatura->vencimento->toDateString(),
            'saldoAnterior' => $linha['saldoAnterior'] ?? $aPagar,
            'compras' => $linha['compras'] ?? 0,
            'receitas' => $linha['receitas'] ?? 0,
            'pagamentos' => $linha['pagamentos'] ?? 0,
            'aPagar' => $aPagar,
            'editavel' => ! $fatura->isFechada($hoje),
            'limites' => [
                'fechamentoAnterior' => $calendario->anterior($fatura)->fechamento->toDateString(),
                'fechamentoProximo' => $calendario->proxima($fatura)->fechamento->toDateString(),
            ],
            'pagaPor' => $this->nomeDaCarteira($fatura),
            'pagamentosLista' => $fatura->exists ? $this->pagamentos($fatura) : [],
        ];
    }

    /**
     * Month of the open invoice, where the card screen opens without a month and with "Hoje" (RN-223,
     * R3).
     */
    public function mesDaAberta(Carteira $cartao, CarbonImmutable $hoje): CarbonImmutable
    {
        return $this->calendario($cartao)->aberta($hoje)->referencia->startOfMonth();
    }

    private function calendario(Carteira $cartao): FaturasDoCartao
    {
        if ($this->calendario?->cartao->carteira_id !== $cartao->id) {
            $this->calendario = FaturasDoCartao::carregar($cartao);
        }

        return $this->calendario;
    }

    private function nomeDaCarteira(Fatura $fatura): ?string
    {
        return $fatura->carteira_vinculada_id === null
            ? null
            : Carteira::query()->whereKey($fatura->carteira_vinculada_id)->value('nome');
    }

    /**
     * @return list<array{id: int, data: string, valor: int, origem: string|null}>
     */
    private function pagamentos(Fatura $fatura): array
    {
        return array_values($fatura->pagamentos()
            ->with('carteira:id,nome')
            ->orderBy('data')
            ->orderBy('id')
            ->get()
            ->map(fn (PagamentoFatura $pagamento): array => [
                'id' => $pagamento->id,
                'data' => $pagamento->data->toDateString(),
                'valor' => $pagamento->valor,
                'origem' => $pagamento->carteira?->nome,
            ])
            ->all());
    }
}
