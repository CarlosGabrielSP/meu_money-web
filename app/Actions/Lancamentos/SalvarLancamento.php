<?php

namespace App\Actions\Lancamentos;

use App\Actions\Rateio\AplicarRateio;
use App\Enums\ModoRateio;
use App\Enums\TipoLancamento;
use App\Models\Lancamento;
use App\Models\User;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SalvarLancamento
{
    public function __construct(private AplicarRateio $aplicarRateio) {}

    /**
     * Create or update an entry and its shares (RN-010 to RN-017, RN-027, RF-070, RF-072).
     *
     * Without a split, the author has a single share with the full amount; with one, shares follow
     * the split (AplicarRateio). The author's chosen status applies to the author's share; without
     * one, a new share gets the initial status by date (RN-013) and an existing one keeps its own.
     * A new attachment replaces the previous one, whose file is removed only after the save commits (CA-044.2).
     * $depois runs inside the same transaction with the saved entry (series creation, mass edits).
     *
     * @param  (Closure(Lancamento): void)|null  $depois
     * @param  array{tipo: TipoLancamento, descricao: string, valor: int, data: string, categoria_id: int, carteira_id: int, observacao: string|null, executado: bool|null, rateio?: array{modo: ModoRateio, participantes: list<array{user_id: int, percentual: string|null, valor: int|null}>}|null}  $dados
     *
     * @throws Throwable
     */
    public function handle(User $autor, array $dados, ?UploadedFile $anexo = null, ?Lancamento $lancamento = null, ?Closure $depois = null): Lancamento
    {
        $lancamento ??= new Lancamento;
        $anexoAnterior = $lancamento->anexo_caminho;
        $novoAnexo = $anexo?->store("anexos/{$autor->id}", 'local');

        try {
            DB::transaction(function () use ($autor, $dados, $anexo, $novoAnexo, $lancamento, $depois): void {
                $lancamento->user_id ??= $autor->id;
                $lancamento->carteira_id = $dados['carteira_id'];
                $lancamento->fill([
                    'tipo' => $dados['tipo'],
                    'descricao' => $dados['descricao'],
                    'valor' => $dados['valor'],
                    'data' => $dados['data'],
                    'observacao' => $dados['observacao'],
                ]);

                if ($anexo !== null && is_string($novoAnexo)) {
                    $lancamento->forceFill([
                        'anexo_caminho' => $novoAnexo,
                        'anexo_nome' => mb_substr($anexo->getClientOriginalName(), 0, 255),
                    ]);
                }

                $lancamento->save();

                $rateio = $dados['rateio'] ?? null;

                $this->aplicarRateio->handle($lancamento, $rateio['modo'] ?? null, $rateio['participantes'] ?? [], [
                    'carteira_id' => $dados['carteira_id'],
                    'categoria_id' => $dados['categoria_id'],
                    'executado' => $dados['executado'],
                ]);

                if ($depois !== null) {
                    $depois($lancamento);
                }
            });
        } catch (Throwable $exception) {
            if (is_string($novoAnexo)) {
                Storage::disk('local')->delete($novoAnexo);
            }

            throw $exception;
        }

        if (is_string($novoAnexo) && $anexoAnterior !== null) {
            Storage::disk('local')->delete($anexoAnterior);
        }

        return $lancamento;
    }
}
