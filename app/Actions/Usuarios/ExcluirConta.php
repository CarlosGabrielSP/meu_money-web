<?php

namespace App\Actions\Usuarios;

use App\Actions\Rateio\SairDoRateio;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Lancamento;
use App\Models\Parcelamento;
use App\Models\QuitacaoContato;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExcluirConta
{
    public function __construct(
        private GarantirAdministradorAtivo $garantirAdministradorAtivo,
        private SairDoRateio $sairDoRateio,
    ) {}

    /**
     * Delete the user and all of their data (RN-112, RN-113).
     *
     * Dependents are removed in an explicit order because wallets and categories are referenced
     * with RESTRICT by shares and series, and the database does not guarantee cascade order.
     * Attachment files live under anexos/{user_id} and are removed after the commit.
     * Shared entries follow RN-113: the author's participants keep their shares as their own entries
     * (CA-007.3), and shares the user received go back to each author (CA-007.4).
     *
     * Card mirrors (RN-248): the mirrors of the user's cards become the contacts' own cards, with
     * their invoices, payments and dates; the user's shares in other authors' mirrors go back to the
     * authors and the user's settlements and "a receber" items in the authors' invoices are deleted.
     */
    public function handle(User $user, string $campoErro = 'password'): void
    {
        DB::transaction(function () use ($user, $campoErro): void {
            $this->garantirAdministradorAtivo->handle($user, $campoErro);

            $user->forceFill(['ultima_carteira_id' => null])->save();

            $this->entregarCotasAosParticipantes($user);
            $this->sairDosRateiosDeOutros($user);

            Cartao::query()->whereIn('cartao_origem_id', Carteira::query()->where('user_id', $user->id)->select('id'))->update(['cartao_origem_id' => null]);
            QuitacaoContato::query()->where('contato_id', $user->id)->delete();

            $user->lancamentos()->delete();
            $user->cotas()->delete();
            $user->parcelamentos()->delete();
            $user->grupos()->delete();
            $user->carteiras()->delete();
            $user->categorias()->delete();

            $user->delete();
        });

        Storage::disk('local')->deleteDirectory("anexos/{$user->id}");
    }

    /**
     * Each participant share of an entry the user authored becomes the participant's own simple entry
     * with the same amount, wallet, category and status (RN-113, CA-007.3). The attachment stays with
     * the deleted author.
     */
    private function entregarCotasAosParticipantes(User $user): void
    {
        $compartilhados = $user->lancamentos()->whereNotNull('modo_rateio')->with('cotas')->get();

        foreach ($compartilhados as $compartilhado) {
            foreach ($compartilhado->cotas->where('user_id', '!=', $user->id) as $cota) {
                $proprio = new Lancamento([
                    'tipo' => $compartilhado->tipo,
                    'descricao' => $compartilhado->descricao,
                    'valor' => $cota->valor,
                    'data' => $compartilhado->data->toDateString(),
                    'observacao' => $compartilhado->observacao,
                ]);
                $proprio->forceFill(['user_id' => $cota->user_id, 'carteira_id' => $cota->carteira_id])->save();

                $cota->forceFill(['lancamento_id' => $proprio->id, 'percentual' => null])->save();
            }
        }
    }

    /**
     * Shares the user received go back to the authors, also in series models and from mirrors (RN-081,
     * RN-113, CA-007.4, RN-248).
     */
    private function sairDosRateiosDeOutros(User $user): void
    {
        $recebidos = Lancamento::query()
            ->where('user_id', '!=', $user->id)
            ->whereHas('cotas', fn ($query) => $query->where('user_id', $user->id))
            ->get();

        foreach ($recebidos as $recebido) {
            $this->sairDoRateio->handle($user, $recebido, permitirFechadas: true, permitirEspelho: true);
        }

        $series = Parcelamento::query()
            ->where('user_id', '!=', $user->id)
            ->whereHas('cotas', fn ($query) => $query->where('user_id', $user->id))
            ->get();

        foreach ($series as $serie) {
            $this->sairDoRateio->sairDaSerie($user, $serie);
        }
    }
}
