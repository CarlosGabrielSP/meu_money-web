<?php

namespace App\Actions\Series;

use App\Actions\Lancamentos\SalvarLancamento;
use App\Actions\Rateio\AplicarRateio;
use App\Enums\EscopoSerie;
use App\Enums\ModoRateio;
use App\Enums\TipoLancamento;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Throwable;

class EditarParcelas
{
    public function __construct(
        private SalvarLancamento $salvarLancamento,
        private AplicarRateio $aplicarRateio,
    ) {}

    /**
     * Edit an installment with a scope (RF-053).
     *
     * "Esta" changes only the open installment (CA-053.1). The other scopes apply description, amount,
     * category, wallet, due day and split to the pending installments of the scope and to the model
     * (RN-053, RN-054, RN-084), never to executed ones (RN-049); date, note, status and attachment stay
     * on the open installment (RN-138). Type and periodicity never change (RN-055). An installment with
     * a participant share already executed keeps its amount and split (RN-085, CA-072.5).
     *
     * @param  array{tipo: TipoLancamento, descricao: string, valor: int, data: string, categoria_id: int, carteira_id: int, observacao: string|null, executado: bool|null, rateio?: array{modo: ModoRateio, participantes: list<array{user_id: int, percentual: string|null, valor: int|null}>}|null}  $dados
     *
     * @throws Throwable
     */
    public function handle(User $autor, Lancamento $parcela, EscopoSerie $escopo, array $dados, ?int $diaVencimento, ?UploadedFile $anexo = null): Lancamento
    {
        /** @var Parcelamento $serie */
        $serie = $parcela->parcelamento;
        $dados['tipo'] = $serie->tipo;

        if ($escopo === EscopoSerie::Esta) {
            return $this->salvarLancamento->handle($autor, $dados, $anexo, $parcela);
        }

        $diaVencimento ??= $serie->dia_vencimento;
        $cotas = $parcela->cotas()->get();
        $cotaAberta = $cotas->firstWhere('user_id', $parcela->user_id);
        $abertaExecutada = $cotaAberta !== null && $cotaAberta->executado;
        $dataOriginal = $parcela->data;
        $dadosDaAberta = $dados;

        if ($abertaExecutada) {
            $dadosDaAberta = [
                ...$dados,
                'descricao' => $parcela->descricao,
                'categoria_id' => $cotaAberta->categoria_id,
                'carteira_id' => $cotaAberta->carteira_id,
            ];
        }

        if ($abertaExecutada || $this->participanteExecutou($parcela, $cotas->all())) {
            $dadosDaAberta = [...$dadosDaAberta, 'valor' => $parcela->valor, 'rateio' => $this->rateioAtual($parcela, $cotas->all())];
        }

        if ($dadosDaAberta['data'] === $dataOriginal->toDateString() && ! $abertaExecutada) {
            $dadosDaAberta['data'] = Parcelamento::noDia($dataOriginal, $diaVencimento)->toDateString();
        }

        return $this->salvarLancamento->handle(
            $autor,
            $dadosDaAberta,
            $anexo,
            $parcela,
            function (Lancamento $aberta) use ($serie, $escopo, $dados, $diaVencimento): void {
                $diaAnterior = $serie->dia_vencimento;
                $rateio = $dados['rateio'] ?? null;

                $serie->update([
                    'descricao' => $dados['descricao'],
                    'valor_parcela' => $dados['valor'],
                    'categoria_id' => $dados['categoria_id'],
                    'carteira_id' => $dados['carteira_id'],
                    'dia_vencimento' => $diaVencimento,
                    'modo_rateio' => $rateio['modo'] ?? null,
                ]);
                $serie->cotas()->delete();

                foreach ($rateio['participantes'] ?? [] as $participante) {
                    $serie->cotas()->create($participante);
                }

                $pendentes = $serie->lancamentos()
                    ->whereKeyNot($aberta->id)
                    ->when($escopo === EscopoSerie::Proximas, fn ($query) => $query->where('numero_parcela', '>', $aberta->numero_parcela))
                    ->pendentesDoAutor()
                    ->with('cotas')
                    ->get();

                Lancamento::query()->whereKey($pendentes->modelKeys())->update(['descricao' => $dados['descricao']]);
                Cota::query()->whereIn('lancamento_id', $pendentes->modelKeys())->where('user_id', $serie->user_id)->update([
                    'categoria_id' => $dados['categoria_id'],
                    'carteira_id' => $dados['carteira_id'],
                ]);

                $simples = $rateio === null
                    ? $pendentes->filter(fn (Lancamento $pendente): bool => $pendente->modo_rateio === null)->modelKeys()
                    : [];

                Lancamento::query()->whereKey($simples)->update(['valor' => $dados['valor']]);
                Cota::query()->whereIn('lancamento_id', $simples)->update(['valor' => $dados['valor']]);

                foreach ($pendentes as $pendente) {
                    if ($diaVencimento !== $diaAnterior) {
                        $pendente->data = Parcelamento::noDia($pendente->data, $diaVencimento);
                    }

                    if (in_array($pendente->id, $simples, true)) {
                        if ($pendente->isDirty()) {
                            $pendente->save();
                        }
                    } elseif (! $this->participanteExecutou($pendente, $pendente->cotas->all())) {
                        $pendente->valor = $dados['valor'];
                        $pendente->save();

                        $this->aplicarRateio->handle($pendente, $rateio['modo'] ?? null, $rateio['participantes'] ?? [], [
                            'carteira_id' => $dados['carteira_id'],
                            'categoria_id' => $dados['categoria_id'],
                            'executado' => null,
                        ]);
                    } else {
                        $pendente->save();
                    }
                }
            },
        );
    }

    /**
     * @param  array<int, Cota>  $cotas
     */
    private function participanteExecutou(Lancamento $parcela, array $cotas): bool
    {
        return collect($cotas)->contains(fn (Cota $cota): bool => $cota->user_id !== $parcela->user_id && $cota->executado);
    }

    /**
     * The split the installment has now, so an installment that cannot be recalculated keeps it.
     *
     * @param  array<int, Cota>  $cotas
     * @return array{modo: ModoRateio, participantes: list<array{user_id: int, percentual: string|null, valor: int|null}>}|null
     */
    private function rateioAtual(Lancamento $parcela, array $cotas): ?array
    {
        if ($parcela->modo_rateio === null) {
            return null;
        }

        return [
            'modo' => $parcela->modo_rateio,
            'participantes' => array_values(collect($cotas)->sortBy('id')->map(fn (Cota $cota): array => [
                'user_id' => $cota->user_id,
                'percentual' => $cota->percentual,
                'valor' => $cota->valor,
            ])->all()),
        ];
    }
}
