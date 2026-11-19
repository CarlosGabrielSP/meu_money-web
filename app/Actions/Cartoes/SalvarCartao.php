<?php

namespace App\Actions\Cartoes;

use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Cota;
use App\Models\Fatura;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalvarCartao
{
    public function __construct(private AtribuirFaturas $atribuirFaturas) {}

    /**
     * Create a card: a wallet of type `cartao`, never the default, plus its row in `cartoes`
     * (RN-190, RN-191, DEC-084).
     *
     * @param  array{nome: string, cor: mixed, icone: mixed, tipo: mixed}  $carteira
     * @param  array{dia_vencimento: int, dias_fechamento: int, carteira_vinculada_id: int|null}  $dados
     */
    public function criar(User $user, array $carteira, array $dados): Carteira
    {
        return DB::transaction(function () use ($user, $carteira, $dados): Carteira {
            $nova = $user->carteiras()->create([...$carteira, 'padrao' => false]);
            $nova->cartao()->create($dados);

            return $nova;
        });
    }

    /**
     * Change the card data (RN-220, RN-229). A new due day or closing recalculates the dates of the
     * open and future invoices, except those edited one by one, and purchases change invoice by date;
     * closed invoices never change. A new linked wallet goes to the open and future invoices; payments
     * already made keep their wallet.
     *
     * @param  array{dia_vencimento: int, dias_fechamento: int, carteira_vinculada_id: int|null}  $dados
     */
    public function atualizar(Carteira $carteira, array $dados): void
    {
        DB::transaction(function () use ($carteira, $dados): void {
            /** @var Cartao $cartao */
            $cartao = $carteira->cartao()->lockForUpdate()->firstOrFail();
            $hoje = CarbonImmutable::today();
            $abertaAntes = FaturasDoCartao::carregar($carteira)->aberta($hoje)->referencia;
            $cartao->fill($dados);
            $mudouDatas = $cartao->isDirty(['dia_vencimento', 'dias_fechamento']);
            $mudouVinculo = $cartao->isDirty('carteira_vinculada_id');
            $cartao->save();

            $naoFechadas = $carteira->faturas()->naoFechadas($hoje);

            if ($mudouVinculo) {
                (clone $naoFechadas)->update(['carteira_vinculada_id' => $cartao->carteira_vinculada_id]);
            }

            if (! $mudouDatas) {
                return;
            }

            $cotas = $this->cotasNaoFechadas($carteira, $hoje);

            (clone $naoFechadas)->where('datas_editadas', false)->get()->each(function (Fatura $fatura) use ($cartao): void {
                $fatura->update([
                    'fechamento' => $cartao->fechamentoPadrao($fatura->referencia),
                    'vencimento' => $cartao->vencimentoPadrao($fatura->referencia),
                ]);
            });

            $this->garantirOrdem($carteira, $abertaAntes, $hoje);
            $this->atribuirFaturas->handle($cotas, permitirFechadas: true);
        });
    }

    /**
     * After the recalculation, the invoice that was open and the later ones do not close before today,
     * and closings stay in order with the neighbors, closed or edited ones included (RN-220,
     * /code-review da Fase 14). Otherwise the change is refused and rolled back.
     *
     * @throws ValidationException
     */
    private function garantirOrdem(Carteira $carteira, CarbonImmutable $abertaAntes, CarbonImmutable $hoje): void
    {
        $calendario = FaturasDoCartao::carregar($carteira);
        $gravadas = $calendario->gravadas();
        $inicio = $abertaAntes->startOfMonth()->subMonthNoOverflow();
        $fim = ($gravadas === [] ? $abertaAntes : max(end($gravadas)->referencia, $abertaAntes))->startOfMonth()->addMonthsNoOverflow(2);
        $anterior = null;

        for ($mes = $inicio; $mes->lessThanOrEqualTo($fim); $mes = $mes->addMonthNoOverflow()) {
            $fatura = $calendario->daReferencia($mes);
            $foraDeOrdem = $anterior !== null && $fatura->fechamento->lessThanOrEqualTo($anterior);
            $fechaAntesDeHoje = $mes->greaterThanOrEqualTo($abertaAntes) && $fatura->fechamento->lessThan($hoje);

            if ($foraDeOrdem || $fechaAntesDeHoje) {
                throw ValidationException::withMessages([
                    'dia_vencimento' => 'Com esse vencimento e fechamento, a fatura aberta fecharia antes de hoje ou fora de ordem. Escolha outros dias.',
                ]);
            }

            $anterior = $fatura->fechamento;
        }
    }

    /**
     * Shares of the card outside closed invoices, taken before the dates change: only they may move
     * (RN-200, RN-220).
     *
     * @return list<int>
     */
    public function cotasNaoFechadas(Carteira $carteira, CarbonImmutable $hoje): array
    {
        return array_values(Cota::query()
            ->where('carteira_id', $carteira->id)
            ->where(fn ($query) => $query->whereNull('fatura_id')->orWhereHas('fatura', fn (Builder $fatura) => $fatura->naoFechadas($hoje)))
            ->pluck('id')
            ->all());
    }
}
