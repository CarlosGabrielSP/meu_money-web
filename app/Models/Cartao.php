<?php

namespace App\Models;

use App\Enums\CompetenciaFatura;
use Carbon\CarbonImmutable;
use Database\Factories\CartaoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data of a credit card, one to one with a wallet of type `cartao` (RN-191, DEC-084).
 *
 * @property int $id
 * @property int $carteira_id
 * @property int $dia_vencimento
 * @property int $dias_fechamento
 * @property CompetenciaFatura $competencia
 * @property int|null $carteira_vinculada_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Carteira $carteira
 * @property-read Carteira|null $carteiraVinculada
 */
#[Fillable(['dia_vencimento', 'dias_fechamento', 'competencia', 'carteira_vinculada_id'])]
class Cartao extends Model
{
    /** @use HasFactory<CartaoFactory> */
    use HasFactory;

    /**
     * Default closing, in days before the due date (RN-191, DEC-085).
     */
    public const int DIAS_FECHAMENTO_PADRAO = 10;

    protected $table = 'cartoes';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'dias_fechamento' => self::DIAS_FECHAMENTO_PADRAO,
        'competencia' => 'fechamento',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dia_vencimento' => 'integer',
            'dias_fechamento' => 'integer',
            'competencia' => CompetenciaFatura::class,
        ];
    }

    /**
     * @return BelongsTo<Carteira, $this>
     */
    public function carteira(): BelongsTo
    {
        return $this->belongsTo(Carteira::class);
    }

    /**
     * Common wallet that pays the invoices; null for an independent card (RN-191, RN-196, RN-197).
     *
     * @return BelongsTo<Carteira, $this>
     */
    public function carteiraVinculada(): BelongsTo
    {
        return $this->belongsTo(Carteira::class, 'carteira_vinculada_id');
    }

    /**
     * Default due date of the invoice of the month: the due day, or the last day of a shorter month
     * (RN-052, RN-191).
     */
    public function vencimentoPadrao(CarbonImmutable $referencia): CarbonImmutable
    {
        return Parcelamento::noDia($referencia->startOfMonth(), $this->dia_vencimento);
    }

    /**
     * Default closing date: the given number of days before the due date (RN-191).
     */
    public function fechamentoPadrao(CarbonImmutable $referencia): CarbonImmutable
    {
        return $this->vencimentoPadrao($referencia)->subDays($this->dias_fechamento);
    }

    /**
     * An invoice of the month with the default dates and the current linked wallet, not saved
     * (RN-192, RN-229).
     */
    public function novaFatura(CarbonImmutable $referencia): Fatura
    {
        $fatura = new Fatura([
            'referencia' => $referencia->startOfMonth(),
            'fechamento' => $this->fechamentoPadrao($referencia),
            'vencimento' => $this->vencimentoPadrao($referencia),
        ]);
        $fatura->carteira_id = $this->carteira_id;
        $fatura->carteira_vinculada_id = $this->carteira_vinculada_id;

        return $fatura;
    }

    /**
     * Months between the competence of an invoice and its reference (due) month: 0 or 1, the same for
     * every invoice of the card (RN-253, data model 05). By closing, the default closing falls in the
     * month before the due date when the due day is not after the closing days; closing days ≤ 25 and
     * RN-052 keep the offset constant in short months.
     */
    public function deslocamentoDaCompetencia(): int
    {
        return $this->competencia === CompetenciaFatura::Fechamento && $this->dia_vencimento <= $this->dias_fechamento ? 1 : 0;
    }

    /**
     * First day of the competence month of the invoice due in the reference month (RN-253).
     */
    public function competenciaDa(CarbonImmutable $referencia): CarbonImmutable
    {
        return $referencia->startOfMonth()->subMonthsNoOverflow($this->deslocamentoDaCompetencia());
    }

    /**
     * First day of the reference (due) month of the invoice of the competence month (RN-253).
     */
    public function referenciaDa(CarbonImmutable $competencia): CarbonImmutable
    {
        return $competencia->startOfMonth()->addMonthsNoOverflow($this->deslocamentoDaCompetencia());
    }

    /**
     * First and last day of the months where the dates of the invoice due in the reference month may
     * be edited (RN-193): the competence month and the next one by closing, the month before and the
     * competence month by due date.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function janelaDeDatas(CarbonImmutable $referencia): array
    {
        $competencia = $this->competenciaDa($referencia);
        $inicio = $this->competencia === CompetenciaFatura::Fechamento ? $competencia : $competencia->subMonthNoOverflow();

        return [$inicio, $inicio->addMonthNoOverflow()->endOfMonth()->startOfDay()];
    }
}
