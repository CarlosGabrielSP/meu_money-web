<?php

namespace App\Models;

use App\Enums\StatusContato;
use Carbon\CarbonImmutable;
use Database\Factories\ContatoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $solicitante_id
 * @property int $convidado_id
 * @property StatusContato $status
 * @property CarbonImmutable|null $respondido_em
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['status', 'respondido_em'])]
class Contato extends Model
{
    /** @use HasFactory<ContatoFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusContato::class,
            'respondido_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitante_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function convidado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'convidado_id');
    }
}
