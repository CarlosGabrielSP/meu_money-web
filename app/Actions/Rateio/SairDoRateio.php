<?php

namespace App\Actions\Rateio;

use App\Actions\Cartoes\AtribuirFaturas;
use App\Enums\EscopoSerie;
use App\Enums\ModoRateio;
use App\Enums\TipoCarteira;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\ParcelamentoCota;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SairDoRateio
{
    public function __construct(
        private CalcularCotas $calcularCotas,
        private AtribuirFaturas $atribuirFaturas,
    ) {}

    /**
     * Remove a participant from a split (RF-073, RN-081).
     *
     * Their share is deleted and its amount added to the author's share, so the entry total does not
     * change. Without an author share, one is created in the author's default wallet with "Outros" and
     * the initial status by date. With no participant left, the entry stops being shared; with
     * participants left, an equal split becomes a fixed one with the current amounts, so a later
     * recalculation does not change the shares of who stayed (RN-146). On an installment, "esta e próximas" and "todas" also apply to later or all installments and remove
     * the participant from the series model (CA-073.4). Other installments where the leaving share or
     * the author's share is already executed stay as they are (RN-147).
     *
     * A share in a closed card invoice counts as executed and never leaves the split (RN-200): the open
     * installment fails, others in the scope stay. $permitirFechadas lifts this when the card or the
     * account is deleted (RN-113, RN-233).
     *
     * A contact never leaves a purchase whose share is in the mirror (RN-208, RN-266): the open
     * installment fails, others in the scope stay, and the contact stays in a series model in a card
     * (RN-267). $permitirEspelho lifts this when the contact deletes the account (RN-248); a missing
     * author share is then created in the entry's card, in the invoice of the date (RN-268).
     *
     * @throws ValidationException
     */
    public function handle(User $participante, Lancamento $lancamento, EscopoSerie $escopo = EscopoSerie::Esta, bool $permitirFechadas = false, bool $permitirEspelho = false): void
    {
        DB::transaction(function () use ($participante, $lancamento, $escopo, $permitirFechadas, $permitirEspelho): void {
            $hoje = CarbonImmutable::today();
            $serie = $lancamento->parcelamento;

            $lancamentos = $serie === null || $escopo === EscopoSerie::Esta
                ? collect([$lancamento])
                : $serie->lancamentos()
                    ->when($escopo === EscopoSerie::Proximas, fn ($query) => $query->where('numero_parcela', '>=', $lancamento->numero_parcela))
                    ->whereHas('cotas', fn ($query) => $query->where('user_id', $participante->id))
                    ->where(fn ($query) => $query->whereKey($lancamento->id)->orWhere(fn ($livres) => $livres
                        ->whereDoesntHave('cotas', fn ($cotas) => $cotas
                            ->whereIn('user_id', [$participante->id, $serie->user_id])
                            ->travadas($hoje))
                        ->when(! $permitirEspelho, fn ($semEspelho) => $semEspelho->whereDoesntHave('cotas', fn ($cotas) => $cotas
                            ->where('user_id', $participante->id)
                            ->whereIn('carteira_id', Cartao::query()->whereNotNull('cartao_origem_id')->select('carteira_id'))))))
                    ->get();

            foreach ($lancamentos as $item) {
                $this->sairDoLancamento($participante, $item, $permitirFechadas ? null : $hoje, $permitirEspelho);
            }

            $modeloNoCartao = ! $permitirEspelho && $serie !== null && Carteira::query()->whereKey($serie->carteira_id)->value('tipo') === TipoCarteira::Cartao;

            if ($serie !== null && $escopo !== EscopoSerie::Esta && ! $modeloNoCartao) {
                $this->sairDaSerie($participante, $serie);
            }
        });
    }

    /**
     * @param  CarbonImmutable|null  $hoje  today, to refuse a share in a closed invoice; null allows it
     *
     * @throws ValidationException
     */
    private function sairDoLancamento(User $participante, Lancamento $lancamento, ?CarbonImmutable $hoje, bool $permitirEspelho): void
    {
        $cotas = $lancamento->cotas()->with('fatura:id,fechamento')->lockForUpdate()->get()->keyBy('user_id');

        /** @var Cota|null $saindo */
        $saindo = $cotas->get($participante->id);

        if ($saindo === null) {
            return;
        }

        if ($hoje !== null && $saindo->fatura !== null && $saindo->fatura->isFechada($hoje)) {
            throw ValidationException::withMessages(['cota' => 'Fatura fechada: a cota não pode sair da divisão.']);
        }

        if (! $permitirEspelho && ($autor = Carteira::autorDoEspelho($saindo->carteira_id)) !== null) {
            throw ValidationException::withMessages(['cota' => "Este cartão tem compras divididas com {$autor}."]);
        }

        /** @var Cota|null $doAutor */
        $doAutor = $cotas->get($lancamento->user_id);
        $noCartao = false;

        if ($doAutor === null) {
            $carteiraDoLancamento = Carteira::query()->find($lancamento->carteira_id);
            $noCartao = $carteiraDoLancamento?->isCartao() === true;
            $executado = ! $noCartao && $lancamento->tipo->executadoPorPadrao($lancamento->data, CarbonImmutable::now());
            $doAutor = new Cota([
                'user_id' => $lancamento->user_id,
                'carteira_id' => $noCartao ? $carteiraDoLancamento->id : $lancamento->user->carteiraPadrao()->id,
                'categoria_id' => Categoria::outros()->id,
                'valor' => 0,
                'executado' => $executado,
                'executado_em' => $executado ? now() : null,
                'vista_em' => now(),
            ]);
            $doAutor->lancamento_id = $lancamento->id;
        }

        $doAutor->valor += $saindo->valor;
        $doAutor->percentual = $this->somarPercentual($lancamento->modo_rateio, $doAutor->percentual, $saindo->percentual);
        $saindo->delete();

        $restam = $cotas->keys()->diff([$participante->id, $lancamento->user_id])->isNotEmpty();

        if (! $restam) {
            $doAutor->percentual = null;
            $lancamento->update(['modo_rateio' => null]);
        } elseif ($lancamento->modo_rateio === ModoRateio::Iguais) {
            $lancamento->update(['modo_rateio' => ModoRateio::Fixo]);
        }

        $doAutor->save();

        if ($noCartao) {
            $this->atribuirFaturas->handle([$doAutor->id], permitirFechadas: true, permitirQuitadas: true);
        }
    }

    /**
     * Remove a participant from the series model; their share goes to the author (RN-081). An equal
     * split becomes a fixed one with the amounts of one installment (RN-146).
     */
    public function sairDaSerie(User $participante, Parcelamento $serie): void
    {
        $modelo = $serie->cotas()->orderBy('id')->get()->keyBy('user_id');

        /** @var ParcelamentoCota|null $saindo */
        $saindo = $modelo->get($participante->id);

        if ($saindo === null) {
            return;
        }

        /** @var ParcelamentoCota $doAutor */
        $doAutor = $modelo->get($serie->user_id) ?? $serie->cotas()->make(['user_id' => $serie->user_id]);

        if ($modelo->keys()->diff([$participante->id, $serie->user_id])->isEmpty()) {
            $serie->cotas()->delete();
            $serie->update(['modo_rateio' => null]);

            return;
        }

        if ($serie->modo_rateio === ModoRateio::Iguais) {
            $this->fixarValores($serie, $modelo->all());
        }

        if ($serie->modo_rateio === ModoRateio::Fixo) {
            $doAutor->valor = (int) $doAutor->valor + (int) $saindo->valor;
        }

        $doAutor->percentual = $this->somarPercentual($serie->modo_rateio, $doAutor->percentual, $saindo->percentual);
        $saindo->delete();
        $doAutor->save();
    }

    /**
     * Turn an equal series split into a fixed one holding the amounts of one installment (RN-146).
     *
     * @param  array<int, ParcelamentoCota>  $modelo  keyed by user id, in inclusion order
     */
    private function fixarValores(Parcelamento $serie, array $modelo): void
    {
        $participantes = array_values(array_map(fn (ParcelamentoCota $cota): array => [
            'user_id' => $cota->user_id,
            'percentual' => null,
            'valor' => null,
        ], $modelo));
        $valores = $this->calcularCotas->handle($serie->valor_parcela, ModoRateio::Iguais, $participantes, $serie->user_id);

        foreach ($modelo as $userId => $cota) {
            $cota->valor = $valores[$userId]['valor'];

            if ($cota->exists) {
                $cota->save();
            }
        }

        $serie->update(['modo_rateio' => ModoRateio::Fixo]);
    }

    /**
     * In percentage mode the author takes the leaving percentage, so the split still adds up to 100%.
     */
    private function somarPercentual(?ModoRateio $modo, ?string $doAutor, ?string $saindo): ?string
    {
        if ($modo !== ModoRateio::Percentual) {
            return $doAutor;
        }

        return number_format((float) $doAutor + (float) $saindo, 2, '.', '');
    }
}
