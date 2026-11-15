<?php

namespace App\Models;

use App\Enums\TipoCategoria;
use Database\Factories\CategoriaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $nome
 * @property TipoCategoria $tipo
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
     * Whether this category belongs to the system instead of a user (RN-030).
     */
    public function isGlobal(): bool
    {
        return $this->user_id === null;
    }
}
