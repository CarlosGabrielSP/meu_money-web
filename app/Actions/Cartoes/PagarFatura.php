<?php

namespace App\Actions\Cartoes;

use App\Enums\OrigemQuitacao;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\PagamentoFatura;
use App\Models\QuitacaoContato;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PagarFatura
{
    public function __construct(
        private PartesDaFatura $partesDaFatura,
        private DistribuirPagamentosDoContato $distribuir,
    ) {}

    /**
     * Pay the invoice due in the month, creating it when needed (RN-195, RN-222). Any amount above
     * zero is accepted, also above what is left or ahead of time; the excess becomes a credit. The
     * payment leaves the wallet linked to the invoice, or no wallet for an independent card (RN-196,
     * RN-197, RN-229).
     *
     * The author may also settle the rest of the part of each marked contact in this invoice only
     * (RN-207, RN-241, RN-256): the payment is the typed amount plus those parts, and each part becomes
     * a settlement of origin `autor`, the "a receber" of a linked card (RN-216). The typed amount may be
     * zero when a contact is marked, as long as the payment is above zero (RN-264). Contacts without an
     * open part are skipped. Afterwards the contacts' mirror payments of the card are redistributed
     * (RN-255), when the payment is a contact's or settles a part; otherwise nothing changes. The
     * author's card row is locked so two payments never settle the same part.
     *
     * A member of the linked wallet pays as the owner; the payment stores who paid, with the stored
     * name (RN-281, RN-309, CA-208.2). A member of the card pays as well, from the linked wallet even
     * when it is not delegated to them, or as money from outside on an independent card (RN-316). The
     * access is checked again under the card lock, so a card relinked meanwhile never takes the payment
     * of a member of the old wallet only; a member of the card still pays (RN-303, RN-326, RN-335).
     *
     * @param  list<int>  $contatos  user ids of the marked contacts
     * @param  User|null  $pagoPor  the member who pays; null is the owner
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function handle(Carteira $cartao, CarbonImmutable $referencia, int $valor, string $data, array $contatos = [], ?User $pagoPor = null): PagamentoFatura
    {
        return DB::transaction(function () use ($cartao, $referencia, $valor, $data, $contatos, $pagoPor): PagamentoFatura {
            DistribuirPagamentosDoContato::travar($cartao);

            if ($pagoPor !== null) {
                // The card row, also a mirror's, so the owner relinking it waits (SalvarCartao).
                Cartao::query()->where('carteira_id', $cartao->id)->lockForUpdate()->first();
            }
            $calendario = FaturasDoCartao::carregar($cartao);
            $fatura = $calendario->salvar($calendario->daReferencia($referencia));

            if ($pagoPor !== null && ! $cartao->isDelegadaA($pagoPor) && ! $fatura->isVinculadaADelegadaDe($pagoPor)) {
                throw new AuthorizationException;
            }
            $quitar = $contatos === [] || $calendario->origem !== null
                ? []
                : array_filter(
                    array_intersect_key($this->partesDaFatura->abertoPorContato($fatura), array_flip($contatos)),
                    fn (int $aberto): bool => $aberto > 0,
                );
            $total = $valor + array_sum($quitar);

            if ($total <= 0) {
                throw ValidationException::withMessages(['valor' => 'Informe um valor maior que zero.']);
            }

            $pagamento = $fatura->pagamentos()->create([
                'carteira_id' => $fatura->carteira_vinculada_id,
                'valor' => $total,
                'data' => $data,
                'pago_por_id' => $pagoPor?->id,
                'pago_por_nome' => $pagoPor?->name,
            ]);

            foreach ($quitar as $contatoId => $aberto) {
                QuitacaoContato::query()->create([
                    'fatura_id' => $fatura->id,
                    'contato_id' => $contatoId,
                    'valor' => $aberto,
                    'origem' => OrigemQuitacao::Autor,
                    'pagamento_fatura_id' => $pagamento->id,
                ]);
            }

            if ($calendario->origem !== null || $quitar !== []) {
                $this->distribuir->doCartao($cartao);
            }

            return $pagamento;
        });
    }
}
