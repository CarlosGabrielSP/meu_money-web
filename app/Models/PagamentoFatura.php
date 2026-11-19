<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PagamentoFaturaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Payment of an invoice; the source wallet is null for an independent card (RN-195 to RN-197).
 *
 * @property int $id
 * @property int $fatura_id
 * @property int|null $carteira_id
 * @property int $valor
 * @property CarbonImmutable $data
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Fatura $fatura
 */
#[Fillable(['carteira_id', 'valor', 'data'])]
class PagamentoFatura extends Model
{
    /** @use HasFactory<PagamentoFaturaFactory> */
    use HasFactory;

    protected $table = 'pagamentos_fatura';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'integer',
            'data' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Fatura, $this>
     */
    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }

    /**
     * @return BelongsTo<Carteira, $this>
     */
    public function carteira(): BelongsTo
    {
        return $this->belongsTo(Carteira::class);
    }
}
