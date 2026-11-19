<?php

namespace App\Actions\Series;

use App\Actions\Cartoes\CalcularFaturas;
use App\Models\Cota;
use App\Models\Parcelamento;
use App\Models\User;
use Carbon\CarbonImmutable;

class RastrearSerie
{
    public function __construct(private CalcularFaturas $calcularFaturas) {}

    /**
     * Tracking of a series for one user (RN-213, RN-215): every existing installment with a share of
     * the user, in any of the user's wallets, and the paid and pending totals. Deleted installments
     * leave a gap in the numbering. One query, whatever the number of installments (RNF-030).
     *
     * An installment in a card is dated by the due date of its invoice, with the purchase date beside
     * it, and counts as paid when its invoice is settled (RN-214, CA-175.2); one more query then.
     *
     * @return array{serie: array{id: int, descricao: string, tipo: string, periodicidade: string, recorrente: bool, totalParcelas: int|null, categoria: array{nome: string, icone: string}|null}, resumo: array{total: int|null, pago: int, pendente: int, pagas: int, existentes: int}, parcelas: list<array{numero: int, data: string, dataCompra: string|null, carteira: array{nome: string, cor: string}, valor: int, executado: bool, vencida: bool}>}
     */
    public function handle(User $user, Parcelamento $parcelamento, CarbonImmutable $hoje): array
    {
        $cotas = Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->join('carteiras', 'carteiras.id', '=', 'cotas.carteira_id')
            ->join('categorias', 'categorias.id', '=', 'cotas.categoria_id')
            ->leftJoin('faturas', 'faturas.id', '=', 'cotas.fatura_id')
            ->where('lancamentos.parcelamento_id', $parcelamento->id)
            ->where('cotas.user_id', $user->id)
            ->orderBy('lancamentos.numero_parcela')
            ->select('cotas.valor', 'cotas.executado', 'cotas.fatura_id', 'faturas.carteira_id as cartao_id', 'faturas.vencimento', 'lancamentos.numero_parcela', 'lancamentos.data')
            ->selectRaw('carteiras.nome as carteira_nome, carteiras.cor as carteira_cor')
            ->selectRaw('categorias.nome as categoria_nome, categorias.icone as categoria_icone')
            ->toBase()
            ->get();

        $cartoes = array_values($cotas->pluck('cartao_id')->filter()->unique()->map(intval(...))->all());
        $quitadas = $cartoes === [] ? [] : CalcularFaturas::quitadas($this->calcularFaturas->handle($user, $cartoes));
        $parcelas = [];
        $pago = 0;
        $pendente = 0;
        $pagas = 0;

        foreach ($cotas as $cota) {
            $noCartao = $cota->fatura_id !== null;
            $compra = CarbonImmutable::parse($cota->data);
            $data = $noCartao ? CarbonImmutable::parse($cota->vencimento) : $compra;
            $valor = (int) $cota->valor;
            $executado = $noCartao ? in_array((int) $cota->fatura_id, $quitadas, true) : (bool) $cota->executado;

            $parcelas[] = [
                'numero' => (int) $cota->numero_parcela,
                'data' => $data->toDateString(),
                'dataCompra' => $noCartao ? $compra->toDateString() : null,
                'carteira' => ['nome' => (string) $cota->carteira_nome, 'cor' => (string) $cota->carteira_cor],
                'valor' => $valor,
                'executado' => $executado,
                'vencida' => ! $executado && $data->lt($hoje),
            ];

            if ($executado) {
                $pago += $valor;
                $pagas++;
            } else {
                $pendente += $valor;
            }
        }

        $ultima = $cotas->last();

        return [
            'serie' => [
                'id' => $parcelamento->id,
                'descricao' => $parcelamento->descricao,
                'tipo' => $parcelamento->tipo->value,
                'periodicidade' => $parcelamento->periodicidade->value,
                'recorrente' => $parcelamento->isRecorrente(),
                'totalParcelas' => $parcelamento->total_parcelas,
                'categoria' => $ultima === null ? null : [
                    'nome' => (string) $ultima->categoria_nome,
                    'icone' => (string) $ultima->categoria_icone,
                ],
            ],
            'resumo' => [
                'total' => $parcelamento->isRecorrente() ? null : $pago + $pendente,
                'pago' => $pago,
                'pendente' => $pendente,
                'pagas' => $pagas,
                'existentes' => count($parcelas),
            ],
            'parcelas' => $parcelas,
        ];
    }
}
