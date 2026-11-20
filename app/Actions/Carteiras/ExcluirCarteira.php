<?php

namespace App\Actions\Carteiras;

use App\Actions\Cartoes\AtribuirFaturas;
use App\Actions\Cartoes\DistribuirPagamentosDoContato;
use App\Actions\Cartoes\Espelhos;
use App\Actions\Lancamentos\RemoverLancamentos;
use App\Actions\Rateio\SairDoRateio;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\Lancamento;
use App\Models\PagamentoFatura;
use App\Models\Parcelamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExcluirCarteira
{
    public function __construct(
        private RemoverLancamentos $removerLancamentos,
        private SairDoRateio $sairDoRateio,
        private AtribuirFaturas $atribuirFaturas,
        private Espelhos $espelhos,
        private DistribuirPagamentosDoContato $distribuirPagamentos,
    ) {}

    /**
     * Delete a non-default wallet, moving its contents to another wallet or deleting them
     * (RN-006, RN-007, RN-132, RN-137).
     *
     * In the cascade (CA-024.4), entries the owner authored are deleted with every share, unless a
     * participant already executed a share (RN-086); shares received from other authors go back to
     * the author (RN-081). When anything blocks, nothing is deleted and the message lists the
     * blocking entries (CA-024.5).
     *
     * A card moves every purchase, closed invoices included, to a common wallet (status by date) or to
     * another card (its invoices by date); its invoices and payments go with it (RN-201, RN-233). A
     * common wallet moves its cards, payments and invoices to the destination; in the cascade its
     * payments are deleted and its cards become independent (RN-201, CA-173.4, CA-173.5).
     *
     * With contacts (Fase 15): a card's mirrors follow its purchases (RN-234 to RN-236, RN-259) and
     * are deleted with it, without the locks of closed invoices and settled parts (RN-233). In the
     * cascade of a mirror's linked wallet the mirror goes to the contact's default wallet (RN-247).
     * Every card that lost payments gets its contacts' distribution rebuilt (RN-241, RN-255). A
     * deleted mirror takes its payments, and through the foreign keys their settlements and automatic
     * receipts, which is the rebuilt distribution of that contact (RN-261 d).
     *
     * @throws ValidationException
     */
    public function handle(Carteira $carteira, ?Carteira $destino = null): void
    {
        $anexos = DB::transaction(function () use ($carteira, $destino): array {
            $padrao = Carteira::query()->whereKey($carteira->id)->lockForUpdate()->value('padrao');

            if ($padrao) {
                throw ValidationException::withMessages([
                    'carteira' => 'A carteira padrão não pode ser excluída. Defina outra carteira como padrão antes.',
                ]);
            }

            $anexos = [];

            if ($carteira->isCartao()) {
                DistribuirPagamentosDoContato::travar($carteira);
            }

            if ($destino !== null) {
                $this->moverConteudo($carteira, $destino);
            } else {
                $anexos = $this->excluirConteudo($carteira);
            }

            $carteira->delete();

            return $anexos;
        });

        Storage::disk('local')->delete($anexos);
    }

    private function moverConteudo(Carteira $carteira, Carteira $destino): void
    {
        $cotas = $carteira->cotas()->pluck('id');
        $doContato = $carteira->isCartao() ? $this->espelhos->moverDoCartao($carteira, $destino) : [];

        $carteira->cotas()->update(['carteira_id' => $destino->id]);
        $carteira->grupos()->update(['carteira_id' => $destino->id]);
        $carteira->parcelamentos()->update(['carteira_id' => $destino->id]);
        Lancamento::query()->where('carteira_id', $carteira->id)->update(['carteira_id' => $destino->id]);

        if ($carteira->isCartao()) {
            $this->atribuirFaturas->handle([...$cotas->all(), ...$doContato], permitirFechadas: true, permitirQuitadas: true);
            $this->espelhos->excluirDoCartao($carteira);

            if ($destino->isCartao() && $doContato !== []) {
                DistribuirPagamentosDoContato::travar($destino);
                $this->distribuirPagamentos->doCartao($destino);
            }

            return;
        }

        Cartao::query()->where('carteira_vinculada_id', $carteira->id)->update(['carteira_vinculada_id' => $destino->id]);
        Fatura::query()->where('carteira_vinculada_id', $carteira->id)->update(['carteira_vinculada_id' => $destino->id]);
        PagamentoFatura::query()->where('carteira_id', $carteira->id)->update(['carteira_id' => $destino->id]);
    }

    /**
     * A card's cascade takes every purchase, also closed or settled ones (RN-233, RN-236). The cards
     * paid from a common wallet are locked first, in id order (RN-240, RN-241).
     *
     * @return array<int, string> attachment files of the deleted entries, removed after the commit
     *
     * @throws ValidationException
     */
    private function excluirConteudo(Carteira $carteira): array
    {
        $dono = $carteira->user;
        $pagos = $this->cartoesPagos($carteira);
        $naCarteira = Cota::query()->where('carteira_id', $carteira->id)->pluck('lancamento_id');

        $noCartao = $carteira->isCartao();
        $proprios = Lancamento::query()
            ->where('user_id', $dono->id)
            ->where(fn (Builder $query) => $query->whereKey($naCarteira)->orWhere(fn (Builder $semCota) => $semCota
                ->where('carteira_id', $carteira->id)
                ->whereDoesntHave('cotas', fn (Builder $cotas) => $cotas->where('user_id', $dono->id))))
            ->withExists(['cotas as participante_executou' => fn (Builder $cotas) => $cotas
                ->where('user_id', '!=', $dono->id)
                ->whereIn('cotas.id', Cota::query()->travadas(CarbonImmutable::today())->select('cotas.id'))])
            ->get();

        $bloqueantes = $noCartao ? collect() : $proprios->filter(fn (Lancamento $lancamento): bool => (bool) $lancamento->getAttribute('participante_executou'));

        if ($bloqueantes->isNotEmpty()) {
            $lista = $bloqueantes
                ->map(fn (Lancamento $lancamento): string => "{$lancamento->descricao} ({$lancamento->data->format('d/m/Y')})")
                ->join(', ');

            throw ValidationException::withMessages([
                'carteira' => "Nada foi excluído. Estes lançamentos têm cota já executada por um participante: {$lista}.",
            ]);
        }

        $recebidos = Lancamento::query()->whereKey($naCarteira)->where('user_id', '!=', $dono->id)->get();

        foreach ($recebidos as $recebido) {
            $this->sairDoRateio->handle($dono, $recebido, permitirFechadas: $noCartao);
        }

        $anexos = $this->removerLancamentos->handle($proprios, ignorarTravas: $noCartao);

        if ($noCartao) {
            $this->espelhos->excluirDoCartao($carteira);
        }

        $this->excluirPagamentosDaCarteira($carteira, $pagos);
        $this->excluirSeries($carteira);

        return $anexos;
    }

    /**
     * In the cascade of a common wallet its payments are deleted and the cards it paid become
     * independent (RN-201); a mirror never does and goes to the contact's default wallet (RN-247).
     * The cards that lost payments get their contacts' distribution rebuilt (RN-241, RN-247).
     *
     * @param  Collection<int, Carteira>  $pagos  cards with payments from the wallet, already locked
     */
    private function excluirPagamentosDaCarteira(Carteira $carteira, Collection $pagos): void
    {
        $espelhos = Cartao::query()->where('carteira_vinculada_id', $carteira->id)->whereNotNull('cartao_origem_id')->pluck('carteira_id');
        $padrao = Carteira::query()->where('user_id', $carteira->user_id)->where('padrao', true)->value('id');

        PagamentoFatura::query()->where('carteira_id', $carteira->id)->delete();
        Cartao::query()->whereIn('carteira_id', $espelhos)->update(['carteira_vinculada_id' => $padrao]);
        Fatura::query()->whereIn('carteira_id', $espelhos)->where('carteira_vinculada_id', $carteira->id)->update(['carteira_vinculada_id' => $padrao]);
        Cartao::query()->where('carteira_vinculada_id', $carteira->id)->update(['carteira_vinculada_id' => null]);
        Fatura::query()->where('carteira_vinculada_id', $carteira->id)->update(['carteira_vinculada_id' => null]);

        $pagos->each(fn (Carteira $cartao) => $this->distribuirPagamentos->doCartao($cartao));
    }

    /**
     * Cards with payments from the wallet, locked in id order so two deletions never wait on each
     * other (RN-240, RN-241).
     *
     * @return Collection<int, Carteira>
     */
    private function cartoesPagos(Carteira $carteira): Collection
    {
        $pagos = Carteira::query()
            ->whereIn('id', Fatura::query()
                ->whereIn('id', PagamentoFatura::query()->where('carteira_id', $carteira->id)->select('fatura_id'))
                ->select('carteira_id'))
            ->orderBy('id')
            ->get();

        $pagos->each(fn (Carteira $cartao) => DistribuirPagamentosDoContato::travar($cartao));

        return $pagos;
    }

    /**
     * Series whose model wallet is deleted are deleted; their installments in other wallets stay as
     * entries without series (RN-137).
     */
    private function excluirSeries(Carteira $carteira): void
    {
        $serieIds = $carteira->parcelamentos()->pluck('id');

        Lancamento::query()->whereIn('parcelamento_id', $serieIds)->update(['numero_parcela' => null]);
        Parcelamento::query()->whereKey($serieIds)->delete();
    }
}
