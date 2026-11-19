<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PagamentoFaturaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /**
     * Settlements this payment made: of origin `autor` for the author's payment, `contato` for the
     * contact's mirror payment (RN-240, RN-241).
     *
     * @return HasMany<QuitacaoContato, $this>
     */
    public function quitacoes(): HasMany
    {
        return $this->hasMany(QuitacaoContato::class);
    }

    /**
     * Automatic receipts this mirror payment made (RN-217, RN-242).
     *
     * @return HasMany<RecebimentoContato, $this>
     */
    public function recebimentos(): HasMany
    {
        return $this->hasMany(RecebimentoContato::class);
    }
}
