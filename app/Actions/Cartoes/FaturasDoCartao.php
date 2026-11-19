<?php

namespace App\Actions\Cartoes;

use App\Enums\EstadoFatura;
use App\Models\Cartao;
use App\Models\Carteira;
use App\Models\Fatura;
use Carbon\CarbonImmutable;

/**
 * The invoices of one card, stored or not yet created (RN-192, RN-221). An invoice is created only
 * when needed (data model 05); until then the month has the default dates of the card. A mirror
 * takes the dates of the origin card's invoice of the same reference month instead, and storing a
 * mirror invoice stores that one too, so the author's invoice always sees the contact's part (RN-238,
 * RN-261 a).
 */
final class FaturasDoCartao
{
    /**
     * @var array<string, Fatura> invoices by "Y-m" of the reference month
     */
    private array $porReferencia = [];

    /**
     * @param  iterable<Fatura>  $faturas  stored invoices of the card
     * @param  FaturasDoCartao|null  $origem  invoices of the origin card, for a mirror
     */
    public function __construct(public readonly Cartao $cartao, iterable $faturas, public readonly ?FaturasDoCartao $origem = null)
    {
        foreach ($faturas as $fatura) {
            $this->porReferencia[$fatura->referencia->format('Y-m')] = $fatura;
        }
    }

    public static function carregar(Carteira $carteira): self
    {
        /** @var Cartao $cartao */
        $cartao = $carteira->cartao()->firstOrFail();
        $origem = $cartao->cartao_origem_id === null
            ? null
            : self::carregar(Carteira::query()->whereKey($cartao->cartao_origem_id)->firstOrFail());

        return new self($cartao, $carteira->faturas()->get(), $origem);
    }

    /**
     * The invoice due in the month: the stored one, else a new one with the default dates.
     */
    public function daReferencia(CarbonImmutable $referencia): Fatura
    {
        $chave = $referencia->format('Y-m');

        return $this->porReferencia[$chave] ??= $this->nova($referencia);
    }

    /**
     * A new invoice with the default dates, or for a mirror with the dates of the origin card's
     * invoice of the month (RN-238).
     */
    private function nova(CarbonImmutable $referencia): Fatura
    {
        $fatura = $this->cartao->novaFatura($referencia);

        if ($this->origem !== null) {
            $modelo = $this->origem->daReferencia($referencia);
            $fatura->fechamento = $modelo->fechamento;
            $fatura->vencimento = $modelo->vencimento;
            $fatura->datas_editadas = $modelo->datas_editadas;
        }

        return $fatura;
    }

    /**
     * The invoice whose period (day after the previous closing until the closing) contains the date
     * (RN-192). The closing comes at most 25 days before the due date, so the month before the date
     * and the three after it cover every case.
     */
    public function daData(CarbonImmutable $data): Fatura
    {
        $dia = $data->startOfDay();
        $mes = $dia->startOfMonth();

        foreach (range(-1, 2) as $indice) {
            $fatura = $this->daReferencia($mes->addMonthsNoOverflow($indice));

            if ($fatura->fechamento->greaterThanOrEqualTo($dia)) {
                return $fatura;
            }
        }

        return $this->daReferencia($mes->addMonthsNoOverflow(3));
    }

    /**
     * The open invoice: its period contains today (RN-221).
     */
    public function aberta(CarbonImmutable $hoje): Fatura
    {
        return $this->daData($hoje);
    }

    public function anterior(Fatura $fatura): Fatura
    {
        return $this->daReferencia($fatura->referencia->subMonthNoOverflow());
    }

    public function proxima(Fatura $fatura): Fatura
    {
        return $this->daReferencia($fatura->referencia->addMonthNoOverflow());
    }

    /**
     * First day of the invoice period: the day after the previous closing (RN-192).
     */
    public function inicioDoPeriodo(Fatura $fatura): CarbonImmutable
    {
        return $this->anterior($fatura)->fechamento->addDay();
    }

    /**
     * Closed (closing before today), open (the period contains today) or future (RN-221).
     */
    public function estado(Fatura $fatura, CarbonImmutable $hoje): EstadoFatura
    {
        return match (true) {
            $fatura->isFechada($hoje) => EstadoFatura::Fechada,
            $this->anterior($fatura)->isFechada($hoje) => EstadoFatura::Aberta,
            default => EstadoFatura::Futura,
        };
    }

    /**
     * Store the invoice when it was not created yet. A concurrent request may create it first: the
     * unique (carteira_id, referencia) index then makes this one reuse it.
     */
    public function salvar(Fatura $fatura): Fatura
    {
        if ($fatura->exists) {
            return $fatura;
        }

        $this->origem?->salvar($this->origem->daReferencia($fatura->referencia));

        $gravada = Fatura::query()->createOrFirst(
            ['carteira_id' => $fatura->carteira_id, 'referencia' => $fatura->referencia],
            [
                'fechamento' => $fatura->fechamento,
                'vencimento' => $fatura->vencimento,
                'carteira_vinculada_id' => $fatura->carteira_vinculada_id,
            ],
        );

        if ($gravada->wasRecentlyCreated && $fatura->datas_editadas) {
            $gravada->forceFill(['datas_editadas' => true])->save();
        }

        return $this->porReferencia[$gravada->referencia->format('Y-m')] = $gravada;
    }

    /**
     * Stored invoices, by reference month.
     *
     * @return list<Fatura>
     */
    public function gravadas(): array
    {
        $gravadas = array_filter($this->porReferencia, fn (Fatura $fatura): bool => $fatura->exists);
        ksort($gravadas);

        return array_values($gravadas);
    }
}
