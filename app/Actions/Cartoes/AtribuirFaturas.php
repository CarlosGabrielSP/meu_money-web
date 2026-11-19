<?php

namespace App\Actions\Cartoes;

use App\Models\Cartao;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AtribuirFaturas
{
    /**
     * Bring the invoice and status of shares in line with their wallet and date, inside the caller's
     * transaction (RN-192, RN-194, RN-228, RN-232).
     *
     * A share in a card enters the invoice of its date, created when needed with the current linked
     * wallet, and has no status. A share that left a card loses its invoice and gets the status by
     * date (RN-013). A closed invoice never changes (RN-200): a share in it stays, and moving it to
     * another wallet fails. A date in a closed invoice fails for the acting user's own share; another
     * user's share, or one the system generates without an acting user, goes to the open invoice
     * instead (DEC-087, RN-232). $permitirFechadas lifts these limits for
     * deleting a card (RN-233) and for recalculating invoices whose dates changed (RN-193, RN-220).
     *
     * @param  iterable<int>  $cotaIds
     *
     * @throws ValidationException
     */
    public function handle(iterable $cotaIds, ?User $ator = null, bool $permitirFechadas = false): void
    {
        $ids = collect($cotaIds)->values()->all();

        if ($ids === []) {
            return;
        }

        $cotas = Cota::query()
            ->whereKey($ids)
            ->with(['lancamento:id,tipo,data', 'fatura', 'carteira:id,tipo'])
            ->get()
            ->filter(fn (Cota $cota): bool => $cota->carteira->isCartao() || $cota->fatura_id !== null);

        if ($cotas->isEmpty()) {
            return;
        }

        $calendarios = $this->calendarios($cotas);
        $hoje = CarbonImmutable::today();
        $agora = CarbonImmutable::now();

        foreach ($cotas as $cota) {
            $atual = $cota->fatura;
            $atualFechada = ! $permitirFechadas && $atual !== null && $atual->isFechada($hoje);

            if (! $cota->carteira->isCartao()) {
                if ($atualFechada) {
                    $this->falharFechada();
                }

                $executado = $cota->lancamento->tipo->executadoPorPadrao($cota->lancamento->data, $agora);
                $cota->forceFill(['fatura_id' => null, 'executado' => $executado, 'executado_em' => $executado ? $agora : null])->save();

                continue;
            }

            if ($atualFechada) {
                if ($atual->carteira_id === $cota->carteira_id) {
                    continue;
                }

                $this->falharFechada();
            }

            $calendario = $calendarios[$cota->carteira_id];
            $alvo = $calendario->daData($cota->lancamento->data);

            if (! $permitirFechadas && $alvo->isFechada($hoje)) {
                $aberta = $calendario->aberta($hoje);

                if ($ator !== null && $cota->user_id === $ator->id) {
                    throw ValidationException::withMessages([
                        'data' => 'Essa data cai numa fatura fechada. Use uma data a partir de '.$calendario->inicioDoPeriodo($aberta)->format('d/m/Y').'.',
                    ]);
                }

                $alvo = $aberta;
            }

            $alvo = $calendario->salvar($alvo);
            $cota->forceFill(['fatura_id' => $alvo->id, 'executado' => false, 'executado_em' => null])->save();
        }
    }

    /**
     * The same for every share of the entries.
     *
     * @param  iterable<int>  $lancamentoIds
     *
     * @throws ValidationException
     */
    public function porLancamentos(iterable $lancamentoIds, ?User $ator = null, bool $permitirFechadas = false): void
    {
        $this->handle(
            Cota::query()->whereIn('lancamento_id', collect($lancamentoIds)->values()->all())->pluck('id'),
            $ator,
            $permitirFechadas,
        );
    }

    /**
     * The invoices of every card the shares are in, loaded once.
     *
     * @param  Collection<int, Cota>  $cotas
     * @return array<int, FaturasDoCartao> by card wallet id
     */
    private function calendarios(Collection $cotas): array
    {
        $cartoes = $cotas->filter(fn (Cota $cota): bool => $cota->carteira->isCartao())->pluck('carteira_id')->unique()->values()->all();
        $faturas = Fatura::query()->whereIn('carteira_id', $cartoes)->get()->groupBy('carteira_id');

        return Cartao::query()
            ->whereIn('carteira_id', $cartoes)
            ->get()
            ->mapWithKeys(fn (Cartao $cartao): array => [
                $cartao->carteira_id => new FaturasDoCartao($cartao, $faturas->get($cartao->carteira_id, collect())),
            ])
            ->all();
    }

    /**
     * @throws ValidationException
     */
    private function falharFechada(): never
    {
        throw ValidationException::withMessages([
            'carteira_id' => 'Fatura fechada: só descrição, categoria, observação e anexo podem mudar.',
        ]);
    }
}
