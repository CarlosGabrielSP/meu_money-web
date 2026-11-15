<?php

namespace App\Actions\Lancamentos;

use App\Models\Cota;
use App\Models\Lancamento;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RemoverLancamentos
{
    /**
     * Delete entries inside the caller's transaction and return their attachment files, to be
     * removed after the commit.
     *
     * Every share is locked first, so a concurrent status change waits. Deletion is refused when a
     * participant already executed a share (RN-086). With $somentePendentes, entries whose author's
     * share became executed meanwhile are kept (RN-049, RN-056).
     *
     * @param  Collection<int, Lancamento>  $lancamentos
     * @return array<int, string>
     *
     * @throws ValidationException
     */
    public function handle(Collection $lancamentos, bool $somentePendentes = false): array
    {
        if ($lancamentos->isEmpty()) {
            return [];
        }

        $autores = $lancamentos->pluck('user_id', 'id');
        $cotas = Cota::query()
            ->whereIn('lancamento_id', $lancamentos->modelKeys())
            ->lockForUpdate()
            ->get(['id', 'lancamento_id', 'user_id', 'executado']);

        if ($somentePendentes) {
            $executados = $cotas
                ->filter(fn (Cota $cota): bool => $cota->executado && $cota->user_id === $autores[$cota->lancamento_id])
                ->pluck('lancamento_id')
                ->all();

            $lancamentos = $lancamentos->reject(fn (Lancamento $lancamento): bool => in_array($lancamento->id, $executados, true));
            $cotas = $cotas->reject(fn (Cota $cota): bool => in_array($cota->lancamento_id, $executados, true));
        }

        $participanteExecutou = $cotas->contains(
            fn (Cota $cota): bool => $cota->executado && $cota->user_id !== $autores[$cota->lancamento_id],
        );

        if ($participanteExecutou) {
            throw ValidationException::withMessages([
                'lancamento' => 'Um participante já marcou a cota como executada. O lançamento não pode ser excluído.',
            ]);
        }

        Lancamento::query()->whereKey($lancamentos->modelKeys())->delete();

        return $lancamentos->map(fn (Lancamento $lancamento): ?string => $lancamento->anexo_caminho)->filter()->values()->all();
    }
}
