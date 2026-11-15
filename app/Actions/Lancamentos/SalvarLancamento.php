<?php

namespace App\Actions\Lancamentos;

use App\Enums\TipoLancamento;
use App\Models\Cota;
use App\Models\Lancamento;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SalvarLancamento
{
    /**
     * Create or update a simple entry and its single share of the author (RN-010 to RN-017, RN-027).
     *
     * A new attachment replaces the previous one, whose file is removed only after the save commits (CA-044.2).
     * Shared entries and installments are refused: they follow the sharing and series rules (RN-027, RN-079, RF-053).
     *
     * @param  array{tipo: TipoLancamento, descricao: string, valor: int, data: string, categoria_id: int, carteira_id: int, observacao: string|null, executado: bool|null}  $dados
     *
     * @throws Throwable
     */
    public function handle(User $autor, array $dados, ?UploadedFile $anexo = null, ?Lancamento $lancamento = null): Lancamento
    {
        $motivo = $lancamento?->motivoEdicaoIndisponivel();

        if ($motivo !== null) {
            throw ValidationException::withMessages(['lancamento' => $motivo]);
        }

        $lancamento ??= new Lancamento;
        $anexoAnterior = $lancamento->anexo_caminho;
        $novoAnexo = $anexo?->store("anexos/{$autor->id}", 'local');

        try {
            DB::transaction(function () use ($autor, $dados, $anexo, $novoAnexo, $lancamento): void {
                $lancamento->user_id ??= $autor->id;
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

                $this->salvarCota($lancamento, $dados);
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

    /**
     * The single share follows amount, wallet, category and status (CA-041.1).
     * Without a chosen status, a new share gets the initial status by date (RN-013) and an existing one keeps its own.
     *
     * @param  array{tipo: TipoLancamento, valor: int, data: string, categoria_id: int, carteira_id: int, executado: bool|null}  $dados
     */
    private function salvarCota(Lancamento $lancamento, array $dados): void
    {
        $cota = $lancamento->cotas()->firstOrNew(['user_id' => $lancamento->user_id]);

        $executado = $dados['executado']
            ?? ($cota->exists
                ? $cota->executado
                : $dados['tipo']->executadoPorPadrao(CarbonImmutable::parse($dados['data']), CarbonImmutable::now()));

        $cota->fill([
            'carteira_id' => $dados['carteira_id'],
            'categoria_id' => $dados['categoria_id'],
            'valor' => $dados['valor'],
        ]);

        $this->aplicarStatus($cota, $executado);

        $cota->save();
    }

    /**
     * Executed shares record when they were executed; pending shares have no date (RN-014).
     */
    private function aplicarStatus(Cota $cota, bool $executado): void
    {
        if ($executado && ! ($cota->exists && $cota->executado)) {
            $cota->executado_em = now();
        }

        if (! $executado) {
            $cota->executado_em = null;
        }

        $cota->executado = $executado;
    }
}
