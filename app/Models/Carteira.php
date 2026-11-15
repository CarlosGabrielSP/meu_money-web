<?php

namespace App\Models;

use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use Database\Factories\CarteiraFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $nome
 * @property bool $padrao
 * @property CorCarteira $cor
 * @property IconeCarteira $icone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['nome', 'padrao', 'cor', 'icone'])]
class Carteira extends Model
{
    /** @use HasFactory<CarteiraFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'padrao' => 'boolean',
            'cor' => CorCarteira::class,
            'icone' => IconeCarteira::class,
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
     * @return HasMany<Cota, $this>
     */
    public function cotas(): HasMany
    {
        return $this->hasMany(Cota::class);
    }

    /**
     * @return HasMany<Parcelamento, $this>
     */
    public function parcelamentos(): HasMany
    {
        return $this->hasMany(Parcelamento::class);
    }

    /**
     * @return HasMany<Grupo, $this>
     */
    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class);
    }
}
