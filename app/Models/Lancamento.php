<?php

namespace App\Models;

use App\Enums\ModoRateio;
use App\Enums\TipoLancamento;
use Database\Factories\LancamentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $descricao
 * @property TipoLancamento $tipo
 * @property int $valor
 * @property Carbon $data
 * @property ModoRateio|null $modo_rateio
 * @property int|null $parcelamento_id
 * @property int|null $numero_parcela
 * @property string|null $observacao
 * @property string|null $anexo_caminho
 * @property string|null $anexo_nome
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'descricao', 'tipo', 'valor', 'data', 'modo_rateio', 'parcelamento_id', 'numero_parcela',
    'observacao', 'anexo_caminho', 'anexo_nome',
])]
class Lancamento extends Model
{
    /** @use HasFactory<LancamentoFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoLancamento::class,
            'valor' => 'integer',
            'data' => 'date',
            'modo_rateio' => ModoRateio::class,
            'numero_parcela' => 'integer',
        ];
    }

    /**
     * Author of the entry (RN-079).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Parcelamento, $this>
     */
    public function parcelamento(): BelongsTo
    {
        return $this->belongsTo(Parcelamento::class);
    }

    /**
     * @return HasMany<Cota, $this>
     */
    public function cotas(): HasMany
    {
        return $this->hasMany(Cota::class);
    }
}
