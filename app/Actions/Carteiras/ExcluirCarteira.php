<?php

namespace App\Actions\Carteiras;

use App\Actions\Lancamentos\RemoverLancamentos;
use App\Actions\Rateio\SairDoRateio;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ExcluirCarteira
{
    public function __construct(
        private RemoverLancamentos $removerLancamentos,
        private SairDoRateio $sairDoRateio,
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
        $carteira->cotas()->update(['carteira_id' => $destino->id]);
        $carteira->grupos()->update(['carteira_id' => $destino->id]);
        $carteira->parcelamentos()->update(['carteira_id' => $destino->id]);
        Lancamento::query()->where('carteira_id', $carteira->id)->update(['carteira_id' => $destino->id]);
    }

    /**
     * @return array<int, string> attachment files of the deleted entries, removed after the commit
     *
     * @throws ValidationException
     */
    private function excluirConteudo(Carteira $carteira): array
    {
        $dono = $carteira->user;
        $naCarteira = Cota::query()->where('carteira_id', $carteira->id)->pluck('lancamento_id');

        $proprios = Lancamento::query()
            ->where('user_id', $dono->id)
            ->where(fn (Builder $query) => $query->whereKey($naCarteira)->orWhere(fn (Builder $semCota) => $semCota
                ->where('carteira_id', $carteira->id)
                ->whereDoesntHave('cotas', fn (Builder $cotas) => $cotas->where('user_id', $dono->id))))
            ->withExists(['cotas as participante_executou' => fn (Builder $cotas) => $cotas
                ->where('user_id', '!=', $dono->id)
                ->where('executado', true)])
            ->get();

        $bloqueantes = $proprios->filter(fn (Lancamento $lancamento): bool => (bool) $lancamento->getAttribute('participante_executou'));

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
            $this->sairDoRateio->handle($dono, $recebido);
        }

        $anexos = $this->removerLancamentos->handle($proprios);

        $serieIds = $carteira->parcelamentos()->pluck('id');

        Lancamento::query()->whereIn('parcelamento_id', $serieIds)->update(['numero_parcela' => null]);
        Parcelamento::query()->whereKey($serieIds)->delete();

        return $anexos;
    }
}
