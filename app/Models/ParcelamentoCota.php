<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ParcelamentoCotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $parcelamento_id
 * @property int $user_id
 * @property int|null $valor
 * @property string|null $percentual
 * @property int|null $grupo_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['user_id', 'valor', 'percentual', 'grupo_id'])]
class ParcelamentoCota extends Model
{
    /** @use HasFactory<ParcelamentoCotaFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'integer',
            'percentual' => 'decimal:2',
        ];
    }

    /**
     * @return BelongsTo<Parcelamento, $this>
     */
    public function parcelamento(): BelongsTo
    {
        return $this->belongsTo(Parcelamento::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Grupo, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }
}
