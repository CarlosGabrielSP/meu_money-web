<?php

namespace App\Actions\Cartoes;

use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\QuitacaoContato;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PartesDaFatura
{
    /**
     * A part is net: purchases minus shared income (RN-239).
     */
    private const string PARTE = "sum(case when lancamentos.tipo = 'despesa' then cotas.valor else -cotas.valor end)";

    /**
     * The part of each contact in the author's invoice (RN-202, RN-239): their shares of the author's
     * purchases in their mirror of the card, in the invoice of the same reference month, net of shared
     * income. One query whatever the number of contacts (CA-183.5).
     *
     * @return Collection<int, array{user_id: int, name: string, parte: int}> by contact name
     */
    public function partes(Fatura $fatura, ?int $autorId = null): Collection
    {
        $autorId ??= (int) Carteira::query()->whereKey($fatura->carteira_id)->value('user_id');

        return $this->dosEspelhos($fatura->carteira_id, $autorId)
            ->join('users', 'users.id', '=', 'cotas.user_id')
            ->where('do_espelho.referencia', $fatura->referencia)
            ->groupBy('cotas.user_id', 'users.name')
            ->selectRaw('cotas.user_id, users.name, '.self::PARTE.' as parte')
            ->orderBy('users.name')
            ->toBase()
            ->get()
            ->map(fn (object $linha): array => ['user_id' => (int) $linha->user_id, 'name' => (string) $linha->name, 'parte' => (int) $linha->parte]);
    }

    /**
     * The part of one contact in each reference month of the author's card (RN-240, RN-255).
     *
     * @return array<string, int> part by "Y-m" of the reference month
     */
    public function porMes(int $origemId, int $contatoId): array
    {
        $autorId = (int) Carteira::query()->whereKey($origemId)->value('user_id');

        return $this->dosEspelhos($origemId, $autorId)
            ->where('cotas.user_id', $contatoId)
            ->groupBy('do_espelho.referencia')
            ->selectRaw('do_espelho.referencia, '.self::PARTE.' as parte')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $linha): array => [CarbonImmutable::parse($linha->referencia)->format('Y-m') => (int) $linha->parte])
            ->all();
    }

    /**
     * What is still open of each contact's part in the invoice: part minus settlements of any origin
     * (RN-241, RN-256). Two queries.
     *
     * @return array<int, int> open amount by contact user id
     */
    public function abertoPorContato(Fatura $fatura): array
    {
        $quitado = QuitacaoContato::query()
            ->where('fatura_id', $fatura->id)
            ->groupBy('contato_id')
            ->selectRaw('contato_id, sum(valor) as total')
            ->pluck('total', 'contato_id');

        return $this->partes($fatura)
            ->mapWithKeys(fn (array $parte): array => [$parte['user_id'] => $parte['parte'] - (int) $quitado->get($parte['user_id'], 0)])
            ->all();
    }

    /**
     * Contacts' shares of the author's purchases in the mirrors of the card, with their mirror invoice
     * as "do_espelho" (RN-203, RN-262).
     *
     * @return Builder<Cota>
     */
    private function dosEspelhos(int $origemId, int $autorId): Builder
    {
        return Cota::query()
            ->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->join('faturas as do_espelho', 'do_espelho.id', '=', 'cotas.fatura_id')
            ->join('cartoes as espelho', 'espelho.carteira_id', '=', 'do_espelho.carteira_id')
            ->where('espelho.cartao_origem_id', $origemId)
            ->where('lancamentos.user_id', $autorId);
    }
}
