<?php

namespace App\Actions\Cartoes;

use App\Enums\OrigemQuitacao;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Fatura;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\RecebimentoContato;
use Carbon\CarbonImmutable;

class DistribuirPagamentosDoContato
{
    public function __construct(private PartesDaFatura $partesDaFatura) {}

    /**
     * Lock the row of the author's card, also for a mirror, so the author's and the contacts'
     * payments of the same card never settle the same part at once (RN-240, RN-241).
     */
    public static function travar(Carteira $cartao): void
    {
        $origemId = Cartao::query()->where('carteira_id', $cartao->id)->value('cartao_origem_id') ?? $cartao->id;

        Cartao::query()->where('carteira_id', $origemId)->lockForUpdate()->first();
    }

    /**
     * Rebuild the distribution of the contacts' mirror payments after a payment of the card was created
     * or deleted (RN-240, RN-241, RN-255): for every mirror of an author's card, or for the one
     * mirror when the card is a mirror. Runs inside the caller's transaction.
     */
    public function doCartao(Carteira $cartao): void
    {
        /** @var Cartao|null $dados */
        $dados = Cartao::query()->where('carteira_id', $cartao->id)->first();

        if ($dados === null) {
            return;
        }

        if ($dados->cartao_origem_id !== null) {
            $this->handle($dados->cartao_origem_id, $cartao->id, $cartao->user_id);

            return;
        }

        Cartao::query()
            ->where('cartao_origem_id', $cartao->id)
            ->join('carteiras', 'carteiras.id', '=', 'cartoes.carteira_id')
            ->get(['cartoes.carteira_id', 'carteiras.user_id'])
            ->each(fn (Cartao $espelho) => $this->handle($cartao->id, $espelho->carteira_id, (int) $espelho->getAttribute('user_id')));
    }

    /**
     * The contact's distribution on one origin card, rebuilt from scratch (RN-255): settlements of
     * origin `contato` and automatic receipts are deleted, then the contact's mirror payments are
     * replayed by date and id. Each payment walks the author's invoices from the oldest to the one of
     * its own reference month: in each, it first settles the contact's open part, then receives the
     * pending "a receber" of the author's settlements of that invoice, on the payment date, or on the
     * author's payment date when that is later (RN-242). Manual
     * receipts stay and count first. What is left is credit in the mirror (RN-240). An author's
     * settlement on an independent card has no "a receber" (RN-216).
     */
    public function handle(int $origemId, int $espelhoId, int $contatoId): void
    {
        $faturasDoAutor = Fatura::query()->where('carteira_id', $origemId)->pluck('id');
        $doContato = QuitacaoContato::query()->whereIn('fatura_id', $faturasDoAutor)->where('contato_id', $contatoId);

        (clone $doContato)->where('origem', OrigemQuitacao::Contato->value)->delete();
        RecebimentoContato::query()
            ->whereNotNull('pagamento_fatura_id')
            ->whereIn('quitacao_contato_id', (clone $doContato)->select('id'))
            ->delete();

        $pagamentos = PagamentoFatura::query()
            ->join('faturas', 'faturas.id', '=', 'pagamentos_fatura.fatura_id')
            ->where('faturas.carteira_id', $espelhoId)
            ->orderBy('pagamentos_fatura.data')
            ->orderBy('pagamentos_fatura.id')
            ->get(['pagamentos_fatura.id', 'pagamentos_fatura.valor', 'pagamentos_fatura.data', 'faturas.referencia']);

        if ($pagamentos->isEmpty()) {
            return;
        }

        $partes = $this->partesDaFatura->porMes($origemId, $contatoId);

        $doAutor = (clone $doContato)
            ->where('origem', OrigemQuitacao::Autor->value)
            ->with(['fatura:id,referencia', 'pagamento:id,carteira_id,data'])
            ->withSum(['recebimentos' => fn ($query) => $query->whereNull('pagamento_fatura_id')], 'valor')
            ->orderBy('id')
            ->get();

        $aberto = [];
        $comAReceber = [];
        $pendente = [];

        foreach ($partes as $mes => $parte) {
            $aberto[$mes] = $parte;
        }

        foreach ($doAutor as $quitacao) {
            $mes = $quitacao->fatura->referencia->format('Y-m');
            $aberto[$mes] = ($aberto[$mes] ?? 0) - $quitacao->valor;

            if ($quitacao->pagamento->carteira_id !== null) {
                $comAReceber[$mes][] = $quitacao;
                $pendente[$quitacao->id] = $quitacao->valor - (int) $quitacao->getAttribute('recebimentos_sum_valor');
            }
        }

        ksort($aberto);
        $calendario = null;

        foreach ($pagamentos as $pagamento) {
            $restante = $pagamento->valor;
            $ate = $this->mes($pagamento->getAttribute('referencia'));
            $data = CarbonImmutable::parse($pagamento->data)->toDateString();

            foreach (array_keys($aberto) as $mes) {
                if ($restante <= 0 || $mes > $ate) {
                    break;
                }

                $quitar = min(max(0, $aberto[$mes]), $restante);

                if ($quitar > 0) {
                    $calendario ??= FaturasDoCartao::carregar(Carteira::query()->whereKey($origemId)->firstOrFail());
                    $fatura = $calendario->salvar($calendario->daReferencia(CarbonImmutable::parse("{$mes}-01")));

                    QuitacaoContato::query()->create([
                        'fatura_id' => $fatura->id,
                        'contato_id' => $contatoId,
                        'valor' => $quitar,
                        'origem' => OrigemQuitacao::Contato,
                        'pagamento_fatura_id' => $pagamento->id,
                    ]);
                    $aberto[$mes] -= $quitar;
                    $restante -= $quitar;
                }

                foreach ($comAReceber[$mes] ?? [] as $quitacao) {
                    $receber = min(max(0, $pendente[$quitacao->id]), $restante);

                    if ($receber <= 0) {
                        continue;
                    }

                    // Never before the author's payment that made the item (RN-242).
                    $quando = max($data, $quitacao->pagamento->data->toDateString());
                    $quitacao->recebimentos()->create(['valor' => $receber, 'data' => $quando, 'pagamento_fatura_id' => $pagamento->id]);
                    $pendente[$quitacao->id] -= $receber;
                    $restante -= $receber;
                }
            }
        }
    }

    private function mes(mixed $referencia): string
    {
        return CarbonImmutable::parse($referencia)->format('Y-m');
    }
}
