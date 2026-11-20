<?php

namespace App\Models;

use App\Enums\StatusMembro;
use Carbon\CarbonImmutable;
use Database\Factories\CarteiraMembroFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Delegation of a common wallet to a contact, the member (RN-269, DEC-097).
 *
 * @property int $id
 * @property int $carteira_id
 * @property int $user_id
 * @property StatusMembro $status
 * @property CarbonImmutable|null $respondido_em
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Carteira $carteira
 * @property-read User $user
 */
#[Fillable(['status', 'respondido_em'])]
class CarteiraMembro extends Model
{
    /** @use HasFactory<CarteiraMembroFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusMembro::class,
            'respondido_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Carteira, $this>
     */
    public function carteira(): BelongsTo
    {
        return $this->belongsTo(Carteira::class);
    }

    /**
     * The member, or the invited user while the invitation is pending.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Pending invitations received by the user from active owners (RN-271, RN-284).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pendentesRecebidos(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)
            ->where('status', StatusMembro::Pendente)
            ->whereHas('carteira.user', fn (Builder $dono) => $dono->whereNull('desativado_em'));
    }

    /**
     * Delegations and invitations between two users, in both directions (RN-283).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function entre(Builder $query, User $um, User $outro): void
    {
        $query->where(fn (Builder $par) => $par
            ->where(fn (Builder $ida) => $ida->where('user_id', $outro->id)->whereHas('carteira', fn (Builder $carteira) => $carteira->where('user_id', $um->id)))
            ->orWhere(fn (Builder $volta) => $volta->where('user_id', $um->id)->whereHas('carteira', fn (Builder $carteira) => $carteira->where('user_id', $outro->id))));
    }
}
