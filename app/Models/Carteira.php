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
     * The contacts' mirrors of an author's card (RN-203).
     *
     * @return HasMany<Cartao, $this>
     */
    public function espelhos(): HasMany
    {
        return $this->hasMany(Cartao::class, 'cartao_origem_id');
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

    /**
     * Members and member invitations of a delegated wallet (RN-269, RN-270).
     *
     * @return HasMany<CarteiraMembro, $this>
     */
    public function membros(): HasMany
    {
        return $this->hasMany(CarteiraMembro::class);
    }

    /**
     * The user is an accepted member of this wallet and its owner is active (RN-272, RN-284).
     */
    public function isDelegadaA(User $user): bool
    {
        return $this->user_id !== $user->id && $user->carteirasDelegadas()->whereKey($this->id)->exists();
    }

    public function isCartao(): bool
    {
        return $this->tipo === TipoCarteira::Cartao;
    }

    /**
     * Whether the wallet is a contact's mirror of an author's card (RN-203).
     */
    public function isEspelho(): bool
    {
        return $this->isCartao() && $this->cartao?->cartao_origem_id !== null;
    }

    /**
     * Name of the author whose card the wallet mirrors; null when it is no mirror (RN-203).
     */
    public static function autorDoEspelho(mixed $carteiraId): ?string
    {
        if (! is_numeric($carteiraId)) {
            return null;
        }

        $nome = User::query()
            ->join('carteiras', 'carteiras.user_id', '=', 'users.id')
            ->join('cartoes', 'cartoes.cartao_origem_id', '=', 'carteiras.id')
            ->where('cartoes.carteira_id', (int) $carteiraId)
            ->value('users.name');

        return is_string($nome) ? $nome : null;
    }
}
