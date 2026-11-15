<?php

namespace App\Models;

use App\Enums\ModoRateio;
use App\Enums\TipoLancamento;
use Carbon\CarbonImmutable;
use Database\Factories\LancamentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $carteira_id
 * @property string $descricao
 * @property TipoLancamento $tipo
 * @property int $valor
 * @property CarbonImmutable $data
 * @property ModoRateio|null $modo_rateio
 * @property int|null $parcelamento_id
 * @property int|null $numero_parcela
 * @property string|null $observacao
 * @property string|null $anexo_caminho
 * @property string|null $anexo_nome
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * Attachment and series fields are set by the server only, never mass assigned.
 */
#[Fillable(['descricao', 'tipo', 'valor', 'data', 'modo_rateio', 'observacao'])]
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

    /**
     * Entries whose author's share is not executed, including entries without an author share
     * (RN-049, RN-056, RN-087).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pendentesDoAutor(Builder $query): void
    {
        $query->whereDoesntHave('cotas', fn (Builder $cotas) => $cotas
            ->whereColumn('cotas.user_id', 'lancamentos.user_id')
            ->where('executado', true))
            ->where(fn (Builder $semCotaDoAutor) => $semCotaDoAutor
                ->whereHas('cotas', fn (Builder $cotas) => $cotas->whereColumn('cotas.user_id', 'lancamentos.user_id'))
                ->orWhereDoesntHave('cotas', fn (Builder $cotas) => $cotas->where('executado', true)));
    }
}
