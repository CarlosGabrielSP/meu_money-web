<?php

namespace App\Actions\Rateio;

use App\Actions\Cartoes\Espelhos;
use App\Enums\ModoRateio;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Lancamento;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class AplicarRateio
{
    public function __construct(
        private CalcularCotas $calcularCotas,
        private Espelhos $espelhos,
    ) {}

    /**
     * Bring the shares of an entry in line with its split (RN-027, RN-070 to RN-077, RN-082, RN-087).
     *
     * Without a mode the entry is simple: a single share of the author with the full amount. The
     * author's share follows the form (RN-077); a new participant's share goes to their default wallet,
     * or to their mirror of the card for a card purchase (RN-203), with the author's category when global, else "Outros", the initial status by date and not seen
     * yet (RN-076, RN-082, RN-013, RN-114). Existing participant shares keep wallet, category, status
     * and seen date (RN-080). Participants left out lose their share (CA-072.3). An author share moved
     * to another wallet leaves its group (RN-130). A mirror left without shares and payments is
     * deleted (RN-237, CA-182.5).
     *
     * When a participant already executed a share, or has it in a closed card invoice, amounts and
     * split cannot change (RN-089, RN-200); internal
     * recalculations of a brand new series skip that check.
     *
     * @param  list<array{user_id: int, percentual: string|null, valor: int|null}>  $participantes
     * @param  array{carteira_id: int, categoria_id: int, executado: bool|null}  $autor
     *
     * @throws ValidationException
     */
    public function handle(Lancamento $lancamento, ?ModoRateio $modo, array $participantes, array $autor, bool $verificarExecutadas = true): void
    {
        $autorId = $lancamento->user_id;
        $semRateio = $modo === null || collect($participantes)->every(fn (array $participante): bool => $participante['user_id'] === $autorId);

        $novas = $semRateio
            ? [$autorId => ['valor' => $lancamento->valor, 'percentual' => null]]
            : $this->calcularCotas->handle($lancamento->valor, $modo, $participantes, $autorId);
        $modo = $semRateio ? null : $modo;

        $atuais = $lancamento->cotas()->with(['user:id,name', 'fatura'])->lockForUpdate()->get()->keyBy('user_id');

        if ($verificarExecutadas) {
            $this->impedirMudancaComExecutadas($lancamento, $modo, $atuais->all(), $novas);
        }

        $agora = CarbonImmutable::now();
        $data = $lancamento->data;
        $executadoPorData = $lancamento->tipo->executadoPorPadrao($data, $agora);
        $participantesNovos = array_diff(array_keys($novas), $atuais->keys()->all(), [$autorId]);
        $carteirasNovas = $this->espelhos->carteirasDosParticipantes($autor['carteira_id'], array_values($participantesNovos));
        $categoriaDoParticipante = $participantesNovos === [] ? null : $this->categoriaDoParticipante($autor['categoria_id']);

        foreach ($novas as $userId => $nova) {
            /** @var Cota|null $cota */
            $cota = $atuais->get($userId);

            if ($userId === $autorId) {
                $cota ??= new Cota(['user_id' => $autorId, 'vista_em' => $agora]);

                if ($cota->exists && $cota->carteira_id !== $autor['carteira_id']) {
                    $cota->grupo_id = null;
                }

                $cota->fill(['carteira_id' => $autor['carteira_id'], 'categoria_id' => $autor['categoria_id']]);
                $this->aplicarStatus($cota, $autor['executado'] ?? ($cota->exists ? $cota->executado : $executadoPorData), $agora);
            } elseif ($cota === null) {
                $cota = new Cota([
                    'user_id' => $userId,
                    'carteira_id' => $carteirasNovas[$userId],
                    'categoria_id' => $categoriaDoParticipante,
                    'vista_em' => null,
                ]);
                $this->aplicarStatus($cota, $executadoPorData, $agora);
            }

            $cota->fill(['valor' => $nova['valor'], 'percentual' => $nova['percentual']]);
            $cota->lancamento_id = $lancamento->id;
            $cota->save();
        }

        $removidas = $atuais->keys()->diff(array_keys($novas))->all();
        $lancamento->cotas()->whereIn('user_id', $removidas)->delete();
        $this->espelhos->excluirVazios($atuais->whereIn('user_id', $removidas)->pluck('carteira_id'));

        if ($lancamento->modo_rateio !== $modo) {
            $lancamento->modo_rateio = $modo;
            $lancamento->save();
        }
    }

    /**
     * @param  array<int, Cota>  $atuais
     * @param  array<int, array{valor: int, percentual: string|null}>  $novas
     *
     * @throws ValidationException
     */
    private function impedirMudancaComExecutadas(Lancamento $lancamento, ?ModoRateio $modo, array $atuais, array $novas): void
    {
        $hoje = CarbonImmutable::today();
        $executaram = collect($atuais)
            ->filter(fn (Cota $cota): bool => $cota->user_id !== $lancamento->user_id && $cota->isTravada($hoje));

        if ($executaram->isEmpty()) {
            return;
        }

        $mudou = $lancamento->getOriginal('modo_rateio') !== $modo
            || collect(array_keys($atuais))->sort()->values()->all() !== collect(array_keys($novas))->sort()->values()->all()
            || collect($novas)->contains(fn (array $nova, int $userId): bool => $atuais[$userId]->valor !== $nova['valor']
                || $this->percentual($atuais[$userId]->percentual) !== $this->percentual($nova['percentual']));

        if ($mudou) {
            $nomes = $executaram->map(fn (Cota $cota): string => $cota->user->name)->join(', ', ' e ');

            throw ValidationException::withMessages([
                'rateio' => "{$nomes} já marcou a cota como executada. Valor e divisão não podem mudar.",
            ]);
        }
    }

    private function percentual(?string $percentual): ?int
    {
        return $percentual === null ? null : $this->calcularCotas->centesimos($percentual);
    }

    /**
     * Participants get the author's category when it is global, else "Outros" (RN-082).
     */
    private function categoriaDoParticipante(int $categoriaId): int
    {
        $categoria = Categoria::query()->whereKey($categoriaId)->firstOrFail();

        return $categoria->isGlobal() ? $categoria->id : Categoria::outros()->id;
    }

    /**
     * Executed shares record when they were executed; pending shares have no date (RN-014).
     */
    private function aplicarStatus(Cota $cota, bool $executado, CarbonImmutable $agora): void
    {
        if ($executado && ! ($cota->exists && $cota->executado)) {
            $cota->executado_em = $agora;
        }

        if (! $executado) {
            $cota->executado_em = null;
        }

        $cota->executado = $executado;
    }
}
