<?php

namespace App\Models;

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
 * @property int|null $carteira_vinculada_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Carteira $carteira
 * @property-read Carteira|null $carteiraVinculada
 */
#[Fillable(['dia_vencimento', 'dias_fechamento', 'carteira_vinculada_id'])]
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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dia_vencimento' => 'integer',
            'dias_fechamento' => 'integer',
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
}
