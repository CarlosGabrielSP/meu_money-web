<?php

namespace App\Models;

use App\Enums\TipoCategoria;
use Carbon\CarbonImmutable;
use Database\Factories\CategoriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $nome
 * @property TipoCategoria $tipo
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['nome', 'tipo'])]
class Categoria extends Model
{
    /** @use HasFactory<CategoriaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCategoria::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Restrict the query to system categories, which have no owner (RN-030).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function globais(Builder $query): void
    {
        $query->whereNull('user_id');
    }

    /**
     * Restrict the query to system categories and those owned by the user (RN-030, RN-031).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function visiveisPara(Builder $query, User $user): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('user_id')->orWhere('user_id', $user->id));
    }

    /**
     * The system category "Outros", which accepts both types (RN-083).
     */
    public static function outros(): self
    {
        return self::query()->globais()->where('nome', 'Outros')->firstOrFail();
    }

    /**
     * Whether this category belongs to the system instead of a user (RN-030).
     */
    public function isGlobal(): bool
    {
        return $this->user_id === null;
    }
}
