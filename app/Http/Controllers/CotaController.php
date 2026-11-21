<?php

namespace App\Http\Controllers;

use App\Actions\Cartoes\AtribuirFaturas;
use App\Actions\Lancamentos\RegistrarQuemAgiu;
use App\Actions\Rateio\SairDoRateio;
use App\Http\Requests\Lancamentos\AlternarExecutadoRequest;
use App\Http\Requests\Rateio\AtualizarCotaRequest;
use App\Http\Requests\Rateio\SairDoRateioRequest;
use App\Models\Cota;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CotaController extends Controller
{
    public function __construct(private RegistrarQuemAgiu $registrarQuemAgiu) {}

    /**
     * Mark or unmark a share as executed (RF-042, RN-014, RN-078, RN-095). A member marks a share of
     * the delegated wallet, which records who acted (RN-293, CA-205.2).
     */
    public function alternarExecutado(AlternarExecutadoRequest $request, Cota $cota): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $executado = $request->boolean('executado');

        if ($executado !== $cota->executado) {
            $cota->forceFill([
                'executado' => $executado,
                'executado_em' => $executado ? now() : null,
            ])->save();

            $this->registrarQuemAgiu->alterou($user, $cota->user, [$cota->lancamento_id]);
        }

        return back();
    }

    /**
     * A participant changes wallet, category and status of their own share (RF-071, RN-080). A share
     * moved to another wallet leaves its group (RN-130). A share moved to the participant's card
     * enters the invoice of the date without status; one moved out of a card gets the status by date
     * (RN-228, RN-231). A member changes the share for the participant owner (RN-277, RN-299).
     */
    public function update(AtualizarCotaRequest $request, Cota $cota, AtribuirFaturas $atribuirFaturas): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $dono = $cota->user;
        $executado = $request->boolean('executado');
        $carteiraId = (int) $request->validated('carteira_id');

        DB::transaction(function () use ($cota, $executado, $carteiraId, $request, $atribuirFaturas, $user, $dono): void {
            $cota->forceFill([
                'grupo_id' => $carteiraId === $cota->carteira_id ? $cota->grupo_id : null,
                'carteira_id' => $carteiraId,
                'categoria_id' => (int) $request->validated('categoria_id'),
                'executado' => $executado,
                'executado_em' => $executado ? ($cota->executado ? $cota->executado_em : now()) : null,
            ])->save();

            $atribuirFaturas->handle([$cota->id], $dono);
            $this->registrarQuemAgiu->alterou($user, $dono, [$cota->lancamento_id]);
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Cota atualizada.']);

        return back();
    }

    /**
     * A participant leaves the split (RF-073). A member leaves for the participant owner, only in
     * the wallets delegated to them (RN-300), and the message names the owner removed (RN-301).
     */
    public function destroy(SairDoRateioRequest $request, Cota $cota, SairDoRateio $sairDoRateio): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $participante = $cota->user;

        $sairDoRateio->handle($participante, $cota->lancamento, $request->escopo(), carteiras: $user->carteirasDelegadasPor($participante));

        Inertia::flash('toast', ['type' => 'success', 'message' => $participante->id === $user->id
            ? 'Você saiu da divisão.'
            : "Você removeu {$participante->name} da divisão."]);

        return back();
    }
}
