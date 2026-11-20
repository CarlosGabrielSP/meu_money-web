<?php

namespace App\Actions\Cartoes;

use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Enums\TipoCarteira;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\Lancamento;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The contacts' mirrors of the author's cards (RN-203, RN-237, RN-238, RN-254, RN-261, DEC-086,
 * DEC-090). Every method runs inside the caller's transaction.
 */
class Espelhos
{
    /**
     * Mirror wallet ids found or created in this request, by "card:contact".
     *
     * @var array<string, int>
     */
    private array $encontrados = [];

    /**
     * Wallet of each new participant share: the contact's mirror of the card when the entry is in a
     * card, else the contact's default wallet (RN-076, RN-203).
     *
     * @param  list<int>  $contatos  user ids
     * @return array<int, int> wallet id by user id
     */
    public function carteirasDosParticipantes(int $carteiraDoLancamento, array $contatos): array
    {
        if ($contatos === []) {
            return [];
        }

        $carteira = Carteira::query()->whereKey($carteiraDoLancamento)->firstOrFail();

        if ($carteira->isCartao()) {
            return collect($contatos)->mapWithKeys(fn (int $contato): array => [$contato => $this->espelho($carteira, $contato)])->all();
        }

        return Carteira::query()->whereIn('user_id', $contatos)->where('padrao', true)->pluck('id', 'user_id')->all();
    }

    /**
     * The contact's mirror of the card, created on the first share (RN-203, RN-237): named
     * "<card> · <author>", icon `credit-card`, default color, linked to the contact's default wallet
     * (RN-206), or to $vinculada when given (RN-234), with the due day, closing days and competence
     * rule of the card (RN-238). The card row is locked so two requests never create two mirrors.
     */
    public function espelho(Carteira $cartao, int $contatoId, ?int $vinculada = null): int
    {
        $chave = "{$cartao->id}:{$contatoId}";

        if (isset($this->encontrados[$chave])) {
            return $this->encontrados[$chave];
        }

        /** @var Cartao $origem */
        $origem = Cartao::query()->where('carteira_id', $cartao->id)->lockForUpdate()->firstOrFail();

        $existente = Carteira::query()
            ->where('user_id', $contatoId)
            ->whereHas('cartao', fn (Builder $query) => $query->where('cartao_origem_id', $cartao->id))
            ->value('id');

        if ($existente !== null) {
            return $this->encontrados[$chave] = (int) $existente;
        }

        $autor = $cartao->user()->value('name');
        $espelho = new Carteira([
            'nome' => mb_substr("{$cartao->nome} · {$autor}", 0, 100),
            'tipo' => TipoCarteira::Cartao,
            'padrao' => false,
            'cor' => CorCarteira::Blue,
            'icone' => IconeCarteira::CreditCard,
        ]);
        $espelho->user_id = $contatoId;
        $espelho->save();

        $dados = new Cartao([
            'dia_vencimento' => $origem->dia_vencimento,
            'dias_fechamento' => $origem->dias_fechamento,
            'competencia' => $origem->competencia,
            'carteira_vinculada_id' => $vinculada ?? Carteira::query()->where('user_id', $contatoId)->where('padrao', true)->value('id'),
        ]);
        $dados->carteira_id = $espelho->id;
        $dados->cartao_origem_id = $cartao->id;
        $dados->save();

        return $this->encontrados[$chave] = $espelho->id;
    }

    /**
     * Contact shares follow the wallet of the entry (RN-203, RN-254): in a card of the author they go
     * to the contact's mirror of that card (created when needed); in a common wallet a share that was
     * in a mirror goes to the mirror's linked wallet. A share the contact already marked as executed
     * cannot enter a mirror (RN-089), and a share whose part was settled never moves (RN-254, RN-258);
     * every share is checked before any moves. Shares that change
     * wallet leave their group (RN-130, RN-261 b).
     * The invoice and status follow afterwards (AtribuirFaturas).
     *
     * @param  iterable<int>  $lancamentoIds
     * @return list<int> wallets the shares left, to delete the mirrors left empty (excluirVazios)
     *
     * @throws ValidationException
     */
    public function acompanharLancamentos(iterable $lancamentoIds): array
    {
        $lancamentos = Lancamento::query()
            ->whereKey(collect($lancamentoIds)->values()->all())
            ->whereNotNull('modo_rateio')
            ->with(['cotas:id,lancamento_id,user_id,carteira_id,fatura_id,executado', 'cotas.user:id,name'])
            ->get(['id', 'user_id', 'carteira_id', 'tipo', 'modo_rateio']);

        if ($lancamentos->isEmpty()) {
            return [];
        }

        Cota::marcarQuitadas($lancamentos->flatMap->cotas);

        $carteiraDe = fn (Lancamento $lancamento): int => $lancamento->cotas->firstWhere('user_id', $lancamento->user_id)->carteira_id ?? $lancamento->carteira_id;
        $carteiras = Carteira::query()
            ->whereKey([...$lancamentos->map($carteiraDe)->all(), ...$lancamentos->flatMap->cotas->pluck('carteira_id')->all()])
            ->with('cartao')
            ->get()
            ->keyBy('id');
        $deixadas = [];
        $executaram = [];
        $quitadas = [];
        $mudancas = [];

        foreach ($lancamentos as $lancamento) {
            /** @var Carteira $doLancamento */
            $doLancamento = $carteiras[$carteiraDe($lancamento)];

            foreach ($lancamento->cotas as $cota) {
                if ($cota->user_id === $lancamento->user_id) {
                    continue;
                }

                $atual = $carteiras[$cota->carteira_id];
                $alvo = match (true) {
                    $doLancamento->isCartao() => $this->espelho($doLancamento, $cota->user_id),
                    $atual->cartao?->isEspelho() === true => $atual->cartao->carteira_vinculada_id
                        ?? Carteira::query()->where('user_id', $cota->user_id)->where('padrao', true)->value('id'),
                    default => $cota->carteira_id,
                };

                if ($alvo === $cota->carteira_id) {
                    continue;
                }

                if ($cota->isQuitada()) {
                    $quitadas[] = $cota->user->name;

                    continue;
                }

                if ($doLancamento->isCartao() && $cota->executado) {
                    $executaram[] = $cota->user->name;

                    continue;
                }

                $mudancas[] = [$cota, $alvo];
            }
        }

        if ($quitadas !== []) {
            throw ValidationException::withMessages(['rateio' => QuitacaoContato::mensagemDeTrava($quitadas)]);
        }

        if ($executaram !== []) {
            $nomes = collect($executaram)->unique()->join(', ', ' e ');

            throw ValidationException::withMessages([
                'rateio' => "{$nomes} já marcou a cota como {$lancamentos->first()->tipo->executadaNaInterface()}. Valor e divisão não podem mudar.",
            ]);
        }

        foreach ($mudancas as [$cota, $alvo]) {
            $deixadas[] = $cota->carteira_id;
            $cota->forceFill(['carteira_id' => $alvo, 'grupo_id' => null])->save();
        }

        return array_values(array_unique($deixadas));
    }

    /**
     * Delete the mirrors among the wallets that have no share and no payment left (RN-237,
     * RN-261 c). Their invoices, card row and groups go with the wallet, and they leave the cache of
     * this request.
     *
     * @param  iterable<int>  $carteiraIds
     */
    public function excluirVazios(iterable $carteiraIds): void
    {
        $ids = collect($carteiraIds)->unique()->values()->all();

        if ($ids === []) {
            return;
        }

        $vazios = Carteira::query()
            ->whereKey($ids)
            ->whereHas('cartao', fn (Builder $query) => $query->whereNotNull('cartao_origem_id'))
            ->whereDoesntHave('cotas')
            ->whereDoesntHave('faturas', fn (Builder $query) => $query->has('pagamentos'))
            ->get()
            ->each(fn (Carteira $espelho) => $espelho->delete());

        $this->encontrados = array_diff($this->encontrados, $vazios->modelKeys());
    }

    /**
     * The author deletes the card moving its purchases (RN-234, RN-235, RN-259, P-046). To another
     * card: each contact's shares go to the contact's mirror of the destination (created when needed,
     * linked to the wallet of the old mirror) and the contact's mirror payments go to the invoice of
     * the same reference month there; settlements are rebuilt afterwards (DistribuirPagamentosDoContato).
     * To a common wallet: the shares go to the mirror's linked wallet and become a common split. The
     * invoice and status of the shares follow afterwards (AtribuirFaturas); then the old mirrors are
     * deleted with what is left in them (excluirDoCartao): the payments, in the common wallet case,
     * with their settlements and receipts.
     *
     * @return list<int> ids of the contacts' shares that moved
     */
    public function moverDoCartao(Carteira $cartao, Carteira $destino): array
    {
        $movidas = [];

        foreach ($this->doCartao($cartao) as $espelho) {
            $contato = $espelho->carteira->user_id;
            $alvo = $destino->isCartao()
                ? $this->espelho($destino, $contato, $espelho->carteira_vinculada_id)
                : $espelho->carteira_vinculada_id ?? Carteira::query()->where('user_id', $contato)->where('padrao', true)->value('id');
            $cotas = Cota::query()->where('carteira_id', $espelho->carteira_id);

            array_push($movidas, ...$cotas->pluck('id')->all());
            $cotas->update(['carteira_id' => $alvo, 'grupo_id' => null]);

            if ($destino->isCartao()) {
                $this->moverPagamentos($espelho->carteira_id, Carteira::query()->whereKey($alvo)->firstOrFail());
            }
        }

        return $movidas;
    }

    /**
     * Delete the card's mirrors with their payments, after their shares moved (RN-234, RN-235) or
     * were deleted in cascade (RN-236); the settlements and receipts of those payments go with them.
     */
    public function excluirDoCartao(Carteira $cartao): void
    {
        $this->doCartao($cartao)->each(fn (Cartao $espelho) => $espelho->carteira->delete());
    }

    /**
     * The mirror payments go to the invoice of the same reference month of the new mirror (RN-234).
     */
    private function moverPagamentos(int $deEspelho, Carteira $paraEspelho): void
    {
        $pagamentos = PagamentoFatura::query()
            ->whereIn('fatura_id', Fatura::query()->where('carteira_id', $deEspelho)->select('id'))
            ->with('fatura:id,referencia')
            ->get();

        if ($pagamentos->isEmpty()) {
            return;
        }

        $calendario = FaturasDoCartao::carregar($paraEspelho);

        foreach ($pagamentos as $pagamento) {
            $fatura = $calendario->salvar($calendario->daReferencia($pagamento->fatura->referencia));
            $pagamento->forceFill(['fatura_id' => $fatura->id])->save();
        }
    }

    /**
     * Mirrors of the card, with their card rows.
     *
     * @return Collection<int, Cartao>
     */
    public function doCartao(Carteira $cartao): Collection
    {
        return Cartao::query()->where('cartao_origem_id', $cartao->id)->with('carteira')->get();
    }

    /**
     * Copy the card to its mirrors after its dates changed (RN-238, RN-220): due day, closing days and
     * competence rule, and the dates of the open and future invoices, from the card's invoice of the
     * same reference month when stored, else the default dates. Closed invoices never change.
     */
    public function copiarDatas(Carteira $cartao, CarbonImmutable $hoje): void
    {
        /** @var Cartao $origem */
        $origem = $cartao->cartao()->firstOrFail();
        $espelhos = $this->doCartao($cartao);

        if ($espelhos->isEmpty()) {
            return;
        }

        $daOrigem = $cartao->faturas()->get()->keyBy(fn (Fatura $fatura): string => $fatura->referencia->format('Y-m'));

        foreach ($espelhos as $espelho) {
            $espelho->update([
                'dia_vencimento' => $origem->dia_vencimento,
                'dias_fechamento' => $origem->dias_fechamento,
                'competencia' => $origem->competencia,
            ]);

            Fatura::query()
                ->where('carteira_id', $espelho->carteira_id)
                ->naoFechadas($hoje)
                ->get()
                ->each(function (Fatura $fatura) use ($daOrigem, $espelho): void {
                    $modelo = $daOrigem->get($fatura->referencia->format('Y-m'));
                    $fatura->forceFill([
                        'fechamento' => $modelo->fechamento ?? $espelho->fechamentoPadrao($fatura->referencia),
                        'vencimento' => $modelo->vencimento ?? $espelho->vencimentoPadrao($fatura->referencia),
                        'datas_editadas' => $modelo->datas_editadas ?? false,
                    ])->save();
                });
        }
    }

    /**
     * Shares of the card's mirrors outside closed invoices, taken before the dates change (RN-200,
     * RN-238).
     *
     * @return list<int>
     */
    public function cotasNaoFechadas(Carteira $cartao, CarbonImmutable $hoje): array
    {
        return array_values(Cota::query()
            ->whereIn('carteira_id', Cartao::query()->where('cartao_origem_id', $cartao->id)->select('carteira_id'))
            ->where(fn ($query) => $query->whereNull('fatura_id')->orWhereHas('fatura', fn (Builder $fatura) => $fatura->naoFechadas($hoje)))
            ->pluck('id')
            ->all());
    }
}
