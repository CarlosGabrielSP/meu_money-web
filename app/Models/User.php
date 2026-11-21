<?php

namespace App\Models;

use App\Enums\Paleta;
use App\Enums\StatusContato;
use App\Enums\StatusMembro;
use App\Enums\TipoCarteira;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property bool $is_admin
 * @property CarbonImmutable|null $desativado_em
 * @property string|null $google_id
 * @property int|null $ultima_carteira_id
 * @property Paleta $paleta
 * @property CarbonImmutable|null $data_nascimento
 * @property bool $modo_simples
 * @property CarbonImmutable|null $primeiro_acesso_em
 * @property CarbonImmutable|null $tutorial_visto_em
 * @property string|null $password
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'paleta', 'data_nascimento', 'modo_simples'])]
#[Hidden(['password', 'remember_token', 'google_id'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Defaults matching the database, so a user built in memory has a palette too (RN-151).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'paleta' => 'neutro',
        'modo_simples' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'desativado_em' => 'datetime',
            'data_nascimento' => 'date:Y-m-d',
            'modo_simples' => 'boolean',
            'primeiro_acesso_em' => 'datetime',
            'tutorial_visto_em' => 'datetime',
        ];
    }

    /**
     * Account palette (RN-151). A stored value outside the enum falls back to Neutro instead of
     * breaking every page of the user.
     *
     * @return Attribute<Paleta, Paleta|string>
     */
    protected function paleta(): Attribute
    {
        return Attribute::make(
            get: fn (?string $valor): Paleta => Paleta::tryFrom($valor ?? '') ?? Paleta::Neutro,
            set: fn (Paleta|string $valor): string => $valor instanceof Paleta ? $valor->value : $valor,
        );
    }

    /**
     * Whether the simple mode question applies on the first access: birth date known, 50 years or
     * older on the given day, and simple mode still off (RN-156, DEC-068).
     */
    public function devePerguntarModoSimples(CarbonImmutable $hoje): bool
    {
        return $this->data_nascimento !== null
            && ! $this->modo_simples
            && $this->data_nascimento->addYears(50)->lessThanOrEqualTo($hoje);
    }

    /**
     * @return HasMany<Carteira, $this>
     */
    public function carteiras(): HasMany
    {
        return $this->hasMany(Carteira::class);
    }

    /**
     * Categories created by the user; system categories are not included (RN-032).
     *
     * @return HasMany<Categoria, $this>
     */
    public function categorias(): HasMany
    {
        return $this->hasMany(Categoria::class);
    }

    /**
     * @return HasMany<Grupo, $this>
     */
    public function grupos(): HasMany
    {
        return $this->hasMany(Grupo::class);
    }

    /**
     * Entries authored by the user (RN-079).
     *
     * @return HasMany<Lancamento, $this>
     */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
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
     * Wallet delegations and member invitations received by the user (RN-270, RN-279).
     *
     * @return HasMany<CarteiraMembro, $this>
     */
    public function delegacoes(): HasMany
    {
        return $this->hasMany(CarteiraMembro::class);
    }

    /**
     * Wallets the user operates as an accepted member while the owner is active (RN-272, RN-284).
     * Own cards are delegated too (RN-313), but until stage 17.2 opens card operation only common
     * wallets count here, so an accepted card is listed in "Delegadas a mim" and still gives 403.
     *
     * @return Builder<Carteira>
     */
    public function carteirasDelegadas(): Builder
    {
        return Carteira::query()
            ->where('carteiras.tipo', TipoCarteira::Comum->value)
            ->whereHas('membros', fn (Builder $membros) => $membros
                ->where('user_id', $this->id)
                ->where('status', StatusMembro::Aceito))
            ->whereHas('user', fn (Builder $dono) => $dono->whereNull('desativado_em'));
    }

    /**
     * Ids of the owner's wallets delegated to the user, which limit what a member reaches in a series
     * (RN-278, RN-291, RN-300); null when the user is the owner, who reaches everything.
     *
     * @return list<int>|null
     */
    public function carteirasDelegadasPor(User $dono): ?array
    {
        if ($dono->id === $this->id) {
            return null;
        }

        return array_values(array_map(intval(...), $this->carteirasDelegadas()->where('carteiras.user_id', $dono->id)->pluck('carteiras.id')->all()));
    }

    /**
     * Last wallet shown on the home screen (RN-115).
     *
     * @return BelongsTo<Carteira, $this>
     */
    public function ultimaCarteira(): BelongsTo
    {
        return $this->belongsTo(Carteira::class, 'ultima_carteira_id');
    }

    /**
     * Active users with an accepted contact with this user, in either direction (RN-062). Sharing
     * uses this query to offer and validate participants (RN-071); deactivated users are left out
     * because new shares with them are blocked (RN-109).
     *
     * @return Builder<User>
     */
    public function consultaDeContatos(): Builder
    {
        $aceitos = Contato::query()->envolvendo($this)->where('status', StatusContato::Aceito);

        return User::query()
            ->where(fn (Builder $query) => $query
                ->whereIn('id', (clone $aceitos)->select('solicitante_id'))
                ->orWhereIn('id', (clone $aceitos)->select('convidado_id')))
            ->whereKeyNot($this->id)
            ->whereNull('desativado_em');
    }

    /**
     * The default wallet, which receives shares from other users (RN-003, RN-076).
     */
    public function carteiraPadrao(): Carteira
    {
        return $this->carteiras()->where('padrao', true)->firstOrFail();
    }
}
