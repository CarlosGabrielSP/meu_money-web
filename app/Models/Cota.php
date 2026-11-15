<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $lancamento_id
 * @property int $user_id
 * @property int $carteira_id
 * @property int $categoria_id
 * @property int|null $grupo_id
 * @property int $valor
 * @property string|null $percentual
 * @property bool $executado
 * @property CarbonImmutable|null $executado_em
 * @property CarbonImmutable|null $vista_em
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'user_id', 'carteira_id', 'categoria_id', 'grupo_id', 'valor', 'percentual',
    'executado', 'executado_em', 'vista_em',
])]
class Cota extends Model
{
    /** @use HasFactory<CotaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'integer',
            'percentual' => 'decimal:2',
            'executado' => 'boolean',
            'executado_em' => 'datetime',
            'vista_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lancamento, $this>
     */
    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Carteira, $this>
     */
    public function carteira(): BelongsTo
    {
        return $this->belongsTo(Carteira::class);
    }

    /**
     * @return BelongsTo<Categoria, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    /**
     * @return BelongsTo<Grupo, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    /**
     * Shares of the user in entries authored by someone else (RN-114).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function recebidasPor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)
            ->whereHas('lancamento', fn (Builder $lancamento) => $lancamento->where('user_id', '!=', $user->id));
    }
}
