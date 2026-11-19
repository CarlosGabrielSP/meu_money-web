<?php

namespace App\Actions\Lancamentos;

use App\Actions\Cartoes\Espelhos;
use App\Models\Cota;
use App\Models\Lancamento;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class RemoverLancamentos
{
    public function __construct(private Espelhos $espelhos) {}

    /**
     * Delete entries inside the caller's transaction and return their attachment files, to be
     * removed after the commit.
     *
     * Every share is locked first, so a concurrent status change waits. Deletion is refused when a
     * participant already executed a share, or has it in a closed card invoice (RN-086, RN-200). With
     * $somentePendentes, entries whose author's share became executed meanwhile are kept (RN-049,
     * RN-056). Mirrors left without shares and payments are deleted (RN-237, RN-261 c).
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
            ->with(['user:id,name', 'fatura:id,fechamento'])
            ->get(['id', 'lancamento_id', 'user_id', 'carteira_id', 'fatura_id', 'executado']);
        $hoje = CarbonImmutable::today();

        if ($somentePendentes) {
            $executados = $cotas
                ->filter(fn (Cota $cota): bool => $cota->isTravada($hoje) && $cota->user_id === $autores[$cota->lancamento_id])
                ->pluck('lancamento_id')
                ->all();

            $lancamentos = $lancamentos->reject(fn (Lancamento $lancamento): bool => in_array($lancamento->id, $executados, true));
            $cotas = $cotas->reject(fn (Cota $cota): bool => in_array($cota->lancamento_id, $executados, true));
        }

        $executaram = $cotas
            ->filter(fn (Cota $cota): bool => $cota->isTravada($hoje) && $cota->user_id !== $autores[$cota->lancamento_id])
            ->map(fn (Cota $cota): string => $cota->user->name)
            ->unique();

        if ($executaram->isNotEmpty()) {
            $nomes = $executaram->join(', ', ' e ');

            throw ValidationException::withMessages([
                'lancamento' => "{$nomes} já marcou a cota como executada. O lançamento não pode ser excluído.",
            ]);
        }

        Lancamento::query()->whereKey($lancamentos->modelKeys())->delete();
        $this->espelhos->excluirVazios($cotas->pluck('carteira_id'));

        return $lancamentos->map(fn (Lancamento $lancamento): ?string => $lancamento->anexo_caminho)->filter()->values()->all();
    }
}
