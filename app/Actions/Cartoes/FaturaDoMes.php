<?php

namespace App\Actions\Cartoes;

use App\Enums\OrigemQuitacao;
use App\Models\Carteira;
use App\Models\Fatura;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\User;
use Carbon\CarbonImmutable;

class FaturaDoMes
{
    /**
     * Invoices of the last card loaded, shared by mesDaAberta and handle in one request.
     */
    private ?FaturasDoCartao $calendario = null;

    public function __construct(
        private CalcularFaturas $calcularFaturas,
        private PartesDaFatura $partesDaFatura,
    ) {}

    /**
     * The invoice card of the card screen for the invoice of the competence month (RN-198, RN-221,
     * RN-223, RN-253):
     * to pay, state, period, closing, due date, previous balance, purchases, income and payments, in a
     * constant number of queries (RNF-030). A month without a stored invoice shows the default dates,
     * to pay = previous balance and no payment (CA-169.7). The payment leaves the wallet linked to the
     * invoice; null is an independent card (RN-196, RN-197, RN-229). "referencia" is the due month,
     * which keys the invoice routes; "competencia" names it.
     *
     * With contacts (RN-202, RN-239, RN-244, RN-257): to pay includes the contacts' parts, purchases and
     * income are the user's own part with the contacts' parts apart, and "partes" lists the author ("Você") and each contact with part,
     * settled (by the contact and by the author) and open; it is empty when no purchase of the invoice
     * is shared. A mirror never edits its dates (RN-238). "aPagarAutor" is the to pay without the
     * contacts' open parts, the amount the "Pagar" form suggests (S3, RN-245).
     *
     * @param  array<int, list<array{fatura: Fatura, compras: int, receitas: int, comprasContatos: int, receitasContatos: int, quitadoPeloAutor: int, pagamentos: int, saldoAnterior: int, aPagar: int, aPagarAutor: int}>>|null  $faturas  CalcularFaturas, to reuse in the request
     * @return array{id: int|null, referencia: string, competencia: string, estado: string, inicio: string, fechamento: string, vencimento: string, saldoAnterior: int, compras: int, receitas: int, comprasContatos: int, receitasContatos: int, pagamentos: int, aPagar: int, aPagarAutor: int, editavel: bool, limites: array{fechamentoMin: string, fechamentoMax: string, vencimentoMin: string, vencimentoMax: string}, pagaPor: string|null, pagamentosLista: list<array{id: int, data: string, valor: int, origem: string|null, carteiraId: int|null, pagoPor: string|null}>, partes: list<array{userId: int, nome: string, voce: bool, parte: int, quitadoPeloContato: int, quitadoPeloAutor: int, aberto: int}>}
     */
    public function handle(User $user, Carteira $cartao, CarbonImmutable $mes, CarbonImmutable $hoje, ?array $faturas = null): array
    {
        $calendario = $this->calendario($cartao);
        $referencia = $calendario->cartao->referenciaDa($mes);
        $fatura = $calendario->daReferencia($referencia);
        $anterior = $calendario->anterior($fatura);
        $proxima = $calendario->proxima($fatura);
        $linhas = ($faturas ?? $this->calcularFaturas->handle($user, [$cartao->id]))[$cartao->id] ?? [];
        $linha = collect($linhas)->first(fn (array $linha): bool => $linha['fatura']->id === $fatura->id);
        $aPagar = $linha['aPagar'] ?? CalcularFaturas::aPagarNoMes($linhas, $referencia);
        $aPagarAutor = $linha['aPagarAutor'] ?? CalcularFaturas::aPagarNoMes($linhas, $referencia, doAutor: true);
        [$janelaInicio, $janelaFim] = $calendario->cartao->janelaDeDatas($fatura->referencia);

        return [
            'id' => $fatura->exists ? $fatura->id : null,
            'referencia' => $fatura->referencia->format('Y-m'),
            'competencia' => $mes->format('Y-m'),
            'estado' => $calendario->estado($fatura, $hoje)->value,
            'inicio' => $anterior->fechamento->addDay()->toDateString(),
            'fechamento' => $fatura->fechamento->toDateString(),
            'vencimento' => $fatura->vencimento->toDateString(),
            'saldoAnterior' => $linha['saldoAnterior'] ?? $aPagar,
            'compras' => $linha['compras'] ?? 0,
            'receitas' => $linha['receitas'] ?? 0,
            'comprasContatos' => $linha['comprasContatos'] ?? 0,
            'receitasContatos' => $linha['receitasContatos'] ?? 0,
            'pagamentos' => $linha['pagamentos'] ?? 0,
            'aPagar' => $aPagar,
            'aPagarAutor' => $aPagarAutor,
            'editavel' => $calendario->origem === null && ! $fatura->isFechada($hoje),
            // Date picker limits (RN-193); the due date also starts at the chosen closing.
            'limites' => [
                'fechamentoMin' => max($hoje->startOfDay(), $janelaInicio, $anterior->fechamento->addDay())->toDateString(),
                'fechamentoMax' => min($janelaFim, $proxima->fechamento->subDay())->toDateString(),
                'vencimentoMin' => max($janelaInicio, $anterior->vencimento->addDay())->toDateString(),
                'vencimentoMax' => min($janelaFim, $proxima->vencimento->subDay())->toDateString(),
            ],
            'pagaPor' => $this->nomeDaCarteira($fatura),
            'pagamentosLista' => $fatura->exists ? $this->pagamentos($fatura) : [],
            'partes' => $fatura->exists && $linha !== null ? $this->partes($user, $fatura, $linha) : [],
        ];
    }

    /**
     * The "Partes" block (RN-202, RN-244, RN-257): the author and each contact with a part in the
     * invoice, by name, in two queries whatever the number of contacts (CA-183.5). The author's line
     * counts as settled the author's payments not used to settle contacts, up to the author's part; a
     * part of zero or less shows nothing settled and nothing open.
     *
     * @param  array{compras: int, receitas: int, quitadoPeloAutor: int, pagamentos: int}  $linha
     * @return list<array{userId: int, nome: string, voce: bool, parte: int, quitadoPeloContato: int, quitadoPeloAutor: int, aberto: int}>
     */
    private function partes(User $user, Fatura $fatura, array $linha): array
    {
        $partes = $this->partesDaFatura->partes($fatura, $user->id);

        if ($partes->isEmpty()) {
            return [];
        }

        $quitado = QuitacaoContato::query()
            ->where('fatura_id', $fatura->id)
            ->groupBy('contato_id', 'origem')
            ->selectRaw('contato_id, origem, sum(valor) as total')
            ->toBase()
            ->get()
            ->groupBy('contato_id');
        $parteDoAutor = $linha['compras'] - $linha['receitas'];
        $quitadoDoAutor = max(0, min($parteDoAutor, $linha['pagamentos'] - $linha['quitadoPeloAutor']));
        $linhas = [[
            'userId' => $user->id,
            'nome' => 'Você',
            'voce' => true,
            'parte' => $parteDoAutor,
            'quitadoPeloContato' => 0,
            'quitadoPeloAutor' => $quitadoDoAutor,
            'aberto' => max(0, $parteDoAutor - $quitadoDoAutor),
        ]];

        foreach ($partes as $parte) {
            $doContato = collect($quitado->get($parte['user_id'], []))->pluck('total', 'origem');
            $peloContato = (int) $doContato->get(OrigemQuitacao::Contato->value, 0);
            $peloAutor = (int) $doContato->get(OrigemQuitacao::Autor->value, 0);

            $linhas[] = [
                'userId' => $parte['user_id'],
                'nome' => $parte['name'],
                'voce' => false,
                'parte' => $parte['parte'],
                'quitadoPeloContato' => $peloContato,
                'quitadoPeloAutor' => $peloAutor,
                'aberto' => $parte['parte'] - $peloContato - $peloAutor,
            ];
        }

        return $linhas;
    }

    /**
     * Competence month of the open invoice, where the card screen opens without a month
     * (RN-223, RN-253, RN-340).
     */
    public function mesDaAberta(Carteira $cartao, CarbonImmutable $hoje): CarbonImmutable
    {
        $calendario = $this->calendario($cartao);

        return $calendario->cartao->competenciaDa($calendario->aberta($hoje)->referencia);
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
     * Each payment with its source wallet and, when a member paid, the member's stored name (RN-306,
     * RN-307).
     *
     * @return list<array{id: int, data: string, valor: int, origem: string|null, carteiraId: int|null, pagoPor: string|null}>
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
                'carteiraId' => $pagamento->carteira_id,
                'pagoPor' => $pagamento->pago_por_nome,
            ])
            ->all());
    }
}
