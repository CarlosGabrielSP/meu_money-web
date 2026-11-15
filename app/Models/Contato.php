<?php

namespace App\Models;

use App\Enums\StatusContato;
use Carbon\CarbonImmutable;
use Database\Factories\ContatoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * The single record of a pair, in either direction (RN-099, RN-141).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function doPar(Builder $query, User $um, User $outro): void
    {
        $query->where(fn (Builder $par) => $par
            ->where(fn (Builder $ida) => $ida->where('solicitante_id', $um->id)->where('convidado_id', $outro->id))
            ->orWhere(fn (Builder $volta) => $volta->where('solicitante_id', $outro->id)->where('convidado_id', $um->id)));
    }

    /**
     * Records where the user is on either side.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function envolvendo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $lados) => $lados->where('solicitante_id', $user->id)->orWhere('convidado_id', $user->id));
    }

    /**
     * Pending invitations received by the user from active requesters (RN-066, RN-109).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pendentesRecebidos(Builder $query, User $user): void
    {
        $query->where('convidado_id', $user->id)
            ->where('status', StatusContato::Pendente)
            ->whereHas('solicitante', fn (Builder $solicitante) => $solicitante->whereNull('desativado_em'));
    }

    /**
     * The other side of the pair for the given user.
     */
    public function outroLado(User $user): User
    {
        return $this->solicitante_id === $user->id ? $this->convidado : $this->solicitante;
    }
}
