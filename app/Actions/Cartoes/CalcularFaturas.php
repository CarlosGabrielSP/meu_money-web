<?php

namespace App\Actions\Cartoes;

use App\Enums\OrigemQuitacao;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class CalcularFaturas
{
    /**
     * Amounts of the stored invoices of the user's cards, in one query whatever their number (RN-195,
     * RN-198, RN-230, RNF-030).
     *
     * To pay = previous balance + purchases − income − payments; the previous balance is what the
     * invoice before left to pay, negative when it left a credit. Invoices never created have no
     * purchases nor payments, so the next stored one carries the balance. "aPagarAte" counts only the
     * payments dated until $ate, for the estimated balance on a past day (RN-196, R5).
     *
     * With contacts (Fase 15, RN-239, RN-245, data model 05): the contacts' parts live in their
     * mirrors, in the invoice of the same reference month; parts are net of shared income. To pay
     * also adds the contacts' parts and subtracts what the contacts settled; a part the author settled
     * is already in the author's payment. "compras" and "receitas" are the author's own part.
     * "aPagarAutor" is the to pay without the contacts' open parts (cumulative), which is what weighs on
     * the linked wallet: previous + author part − author payments + author settlements.
     *
     * @param  list<int>|null  $cartoes  card wallet ids, or every card of the user
     * @return array<int, list<array{fatura: Fatura, compras: int, receitas: int, comprasContatos: int, receitasContatos: int, quitadoPorContatos: int, quitadoPeloAutor: int, pagamentos: int, saldoAnterior: int, aPagar: int, aPagarAte: int, aPagarAutor: int, aPagarAutorAte: int}>> by card wallet id, by reference month
     */
    public function handle(User $user, ?array $cartoes = null, ?CarbonImmutable $ate = null): array
    {
        $ateExclusivo = ($ate ?? CarbonImmutable::today())->startOfDay()->addDay()->toDateString();
        $somaDasCotas = fn (string $tipo): Builder => Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->whereColumn('cotas.fatura_id', 'faturas.id')
            ->where('lancamentos.tipo', $tipo)
            ->selectRaw('coalesce(sum(cotas.valor), 0)');
        $somaDosContatos = fn (string $tipo): Builder => Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->join('faturas as do_espelho', 'do_espelho.id', '=', 'cotas.fatura_id')
            ->join('cartoes as espelho', 'espelho.carteira_id', '=', 'do_espelho.carteira_id')
            ->whereColumn('espelho.cartao_origem_id', 'faturas.carteira_id')
            ->whereColumn('do_espelho.referencia', 'faturas.referencia')
            ->whereColumn('lancamentos.user_id', 'carteiras.user_id')
            ->where('lancamentos.tipo', $tipo)
            ->selectRaw('coalesce(sum(cotas.valor), 0)');
        $somaDasQuitacoes = fn (OrigemQuitacao $origem, bool $comLimite): Builder => QuitacaoContato::query()
            ->join('pagamentos_fatura', 'pagamentos_fatura.id', '=', 'quitacoes_contato.pagamento_fatura_id')
            ->whereColumn('quitacoes_contato.fatura_id', 'faturas.id')
            ->where('quitacoes_contato.origem', $origem->value)
            ->when($comLimite, fn (Builder $query) => $query->where('pagamentos_fatura.data', '<', $ateExclusivo))
            ->selectRaw('coalesce(sum(quitacoes_contato.valor), 0)');

        $linhas = Fatura::query()
            ->join('carteiras', 'carteiras.id', '=', 'faturas.carteira_id')
            ->where('carteiras.user_id', $user->id)
            ->when($cartoes !== null, fn (Builder $query) => $query->whereIn('faturas.carteira_id', $cartoes))
            ->select('faturas.*')
            ->selectSub($somaDasCotas('despesa'), 'soma_compras')
            ->selectSub($somaDasCotas('receita'), 'soma_receitas')
            ->selectSub($somaDosContatos('despesa'), 'soma_compras_contatos')
            ->selectSub($somaDosContatos('receita'), 'soma_receitas_contatos')
            ->selectSub($somaDasQuitacoes(OrigemQuitacao::Contato, false), 'soma_quitado_contatos')
            ->selectSub($somaDasQuitacoes(OrigemQuitacao::Contato, true), 'soma_quitado_contatos_ate')
            ->selectSub($somaDasQuitacoes(OrigemQuitacao::Autor, false), 'soma_quitado_autor')
            ->selectSub($somaDasQuitacoes(OrigemQuitacao::Autor, true), 'soma_quitado_autor_ate')
            ->selectSub(PagamentoFatura::query()->whereColumn('fatura_id', 'faturas.id')->selectRaw('coalesce(sum(valor), 0)'), 'soma_pagamentos')
            ->selectSub(PagamentoFatura::query()->whereColumn('fatura_id', 'faturas.id')->where('data', '<', $ateExclusivo)->selectRaw('coalesce(sum(valor), 0)'), 'soma_pagamentos_ate')
            ->orderBy('faturas.carteira_id')
            ->orderBy('faturas.referencia')
            ->get();

        $porCartao = [];
        $acumulados = [];

        foreach ($linhas as $fatura) {
            $cartao = $fatura->carteira_id;
            [$acumulado, $acumuladoAte, $doAutor, $doAutorAte] = $acumulados[$cartao] ?? [0, 0, 0, 0];
            $valor = fn (string $coluna): int => (int) $fatura->getAttribute($coluna);
            $parteDoAutor = $valor('soma_compras') - $valor('soma_receitas');
            $partesDosContatos = $valor('soma_compras_contatos') - $valor('soma_receitas_contatos');
            $saldoAnterior = $acumulado;

            $acumulado += $parteDoAutor + $partesDosContatos - $valor('soma_quitado_contatos') - $valor('soma_pagamentos');
            $acumuladoAte += $parteDoAutor + $partesDosContatos - $valor('soma_quitado_contatos_ate') - $valor('soma_pagamentos_ate');
            $doAutor += $parteDoAutor - $valor('soma_pagamentos') + $valor('soma_quitado_autor');
            $doAutorAte += $parteDoAutor - $valor('soma_pagamentos_ate') + $valor('soma_quitado_autor_ate');
            $acumulados[$cartao] = [$acumulado, $acumuladoAte, $doAutor, $doAutorAte];

            $porCartao[$cartao][] = [
                'fatura' => $fatura,
                'compras' => $valor('soma_compras'),
                'receitas' => $valor('soma_receitas'),
                'comprasContatos' => $valor('soma_compras_contatos'),
                'receitasContatos' => $valor('soma_receitas_contatos'),
                'quitadoPorContatos' => $valor('soma_quitado_contatos'),
                'quitadoPeloAutor' => $valor('soma_quitado_autor'),
                'pagamentos' => $valor('soma_pagamentos'),
                'saldoAnterior' => $saldoAnterior,
                'aPagar' => $acumulado,
                'aPagarAte' => $acumuladoAte,
                'aPagarAutor' => $doAutor,
                'aPagarAutorAte' => $doAutorAte,
            ];
        }

        return $porCartao;
    }

    /**
     * To pay of the invoice due in the month: the stored one, else the balance left by the last one
     * before it (RN-195). $doAutor picks the author's own to pay, without the contacts' open parts (RN-245).
     *
     * @param  list<array{fatura: Fatura, aPagar: int, aPagarAutor?: int}>  $faturas  of one card, by reference month
     */
    public static function aPagarNoMes(array $faturas, CarbonImmutable $referencia, bool $doAutor = false): int
    {
        $aPagar = 0;

        foreach ($faturas as $linha) {
            if ($linha['fatura']->referencia->greaterThan($referencia->startOfMonth())) {
                break;
            }

            $aPagar = $doAutor ? ($linha['aPagarAutor'] ?? 0) : $linha['aPagar'];
        }

        return $aPagar;
    }

    /**
     * Invoices whose to pay is zero or less: the user's part in them is settled (RN-214, RN-219).
     *
     * @param  array<int, list<array{fatura: Fatura, aPagar: int}>>  $porCartao
     * @return list<int> invoice ids
     */
    public static function quitadas(array $porCartao): array
    {
        $ids = [];

        foreach ($porCartao as $faturas) {
            foreach ($faturas as $linha) {
                if ($linha['aPagar'] <= 0) {
                    $ids[] = $linha['fatura']->id;
                }
            }
        }

        return $ids;
    }
}
