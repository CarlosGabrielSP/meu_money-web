<?php

namespace App\Models;

use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Enums\TipoCarteira;
use Carbon\CarbonImmutable;
use Database\Factories\CarteiraFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $user_id
 * @property string $nome
 * @property TipoCarteira $tipo
 * @property bool $padrao
 * @property CorCarteira $cor
 * @property IconeCarteira $icone
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Cartao|null $cartao
 */
#[Fillable(['nome', 'tipo', 'padrao', 'cor', 'icone'])]
class Carteira extends Model
{
    /** @use HasFactory<CarteiraFactory> */
    use HasFactory;

    /**
     * Defaults matching the database, so a wallet built in memory is a common one (RN-190).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'tipo' => 'comum',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoCarteira::class,
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

    /**
     * Card data, only for a wallet of type `cartao` (DEC-084).
     *
     * @return HasOne<Cartao, $this>
     */
    public function cartao(): HasOne
    {
        return $this->hasOne(Cartao::class);
    }

    /**
     * Invoices of a card (RN-192).
     *
     * @return HasMany<Fatura, $this>
     */
    public function faturas(): HasMany
    {
        return $this->hasMany(Fatura::class);
    }

    public function isCartao(): bool
    {
        return $this->tipo === TipoCarteira::Cartao;
    }
}
