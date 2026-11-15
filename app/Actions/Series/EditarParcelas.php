<?php

namespace App\Actions\Series;

use App\Actions\Lancamentos\SalvarLancamento;
use App\Enums\EscopoSerie;
use App\Enums\TipoLancamento;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Throwable;

class EditarParcelas
{
    public function __construct(private SalvarLancamento $salvarLancamento) {}

    /**
     * Edit an installment with a scope (RF-053).
     *
     * "Esta" changes only the open installment (CA-053.1). The other scopes apply description, amount,
     * category, wallet and due day to the pending installments of the scope and to the model (RN-053,
     * RN-054), never to executed ones (RN-049); date, note, status and attachment stay on the open
     * installment (RN-138). Type and periodicity never change (RN-055).
     *
     * @param  array{tipo: TipoLancamento, descricao: string, valor: int, data: string, categoria_id: int, carteira_id: int, observacao: string|null, executado: bool|null}  $dados
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
        $cotaAberta = $this->cotaDoAutor($parcela);
        $dataOriginal = $parcela->data;

        $dadosDaAberta = $cotaAberta->executado
            ? [
                ...$dados,
                'descricao' => $parcela->descricao,
                'valor' => $parcela->valor,
                'categoria_id' => $cotaAberta->categoria_id,
                'carteira_id' => $cotaAberta->carteira_id,
            ]
            : $dados;

        if ($dadosDaAberta['data'] === $dataOriginal->toDateString() && ! $cotaAberta->executado) {
            $dadosDaAberta['data'] = Parcelamento::noDia($dataOriginal, $diaVencimento)->toDateString();
        }

        return $this->salvarLancamento->handle(
            $autor,
            $dadosDaAberta,
            $anexo,
            $parcela,
            function (Lancamento $aberta) use ($serie, $escopo, $dados, $diaVencimento): void {
                $diaAnterior = $serie->dia_vencimento;

                $serie->update([
                    'descricao' => $dados['descricao'],
                    'valor_parcela' => $dados['valor'],
                    'categoria_id' => $dados['categoria_id'],
                    'carteira_id' => $dados['carteira_id'],
                    'dia_vencimento' => $diaVencimento,
                ]);

                $pendentes = $serie->lancamentos()
                    ->whereKeyNot($aberta->id)
                    ->when($escopo === EscopoSerie::Proximas, fn ($query) => $query->where('numero_parcela', '>', $aberta->numero_parcela))
                    ->pendentesDoAutor()
                    ->get(['id', 'data']);

                Lancamento::query()->whereKey($pendentes->modelKeys())->update([
                    'descricao' => $dados['descricao'],
                    'valor' => $dados['valor'],
                ]);
                Cota::query()->whereIn('lancamento_id', $pendentes->modelKeys())->where('user_id', $serie->user_id)->update([
                    'valor' => $dados['valor'],
                    'categoria_id' => $dados['categoria_id'],
                    'carteira_id' => $dados['carteira_id'],
                ]);

                if ($diaVencimento !== $diaAnterior) {
                    foreach ($pendentes as $pendente) {
                        $pendente->update(['data' => Parcelamento::noDia($pendente->data, $diaVencimento)->toDateString()]);
                    }
                }
            },
        );
    }

    private function cotaDoAutor(Lancamento $parcela): Cota
    {
        return $parcela->cotas()->where('user_id', $parcela->user_id)->sole();
    }
}
