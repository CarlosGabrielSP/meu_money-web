<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\CotaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $lancamento_id
 * @property int $user_id
 * @property int $carteira_id
 * @property int|null $fatura_id
 * @property int $categoria_id
 * @property int|null $grupo_id
 * @property int $valor
 * @property string|null $percentual
 * @property bool $executado
 * @property CarbonImmutable|null $executado_em
 * @property CarbonImmutable|null $vista_em
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Lancamento $lancamento required foreign key, never null
 * @property-read Fatura|null $fatura
 */
#[Fillable([
    'user_id', 'carteira_id', 'categoria_id', 'grupo_id', 'valor', 'percentual',
    'executado', 'executado_em', 'vista_em',
])]
class Cota extends Model
{
    /** @use HasFactory<CotaFactory> */
    use HasFactory;

    /**
     * Effective date of a share in the main screen and the report: the due date of its invoice for a
     * card purchase, else the entry date (RN-199). Needs the joins of naCarteiraPorVencimento.
     */
    public const string DATA_EFETIVA = 'coalesce(faturas.vencimento, lancamentos.data)';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'integer',
            'percentual' => 'decimal:2',
            'executado' => 'boolean',
            'executado_em' => 'datetime',
            'vista_em' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Lancamento, $this>
     */
    public function lancamento(): BelongsTo
    {
        return $this->belongsTo(Lancamento::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Carteira, $this>
     */
    public function carteira(): BelongsTo
    {
        return $this->belongsTo(Carteira::class);
    }

    /**
     * @return BelongsTo<Categoria, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    /**
     * @return BelongsTo<Grupo, $this>
     */
    public function grupo(): BelongsTo
    {
        return $this->belongsTo(Grupo::class);
    }

    /**
     * Invoice of a card share; null outside cards (RN-192).
     *
     * @return BelongsTo<Fatura, $this>
     */
    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }

    /**
     * A share counts as executed when marked so or, in a card, when its invoice is closed: it no
     * longer changes amount, date or split (RN-049, RN-085, RN-086, RN-089, RN-200). Needs the
     * invoice loaded.
     */
    public function isTravada(CarbonImmutable $hoje): bool
    {
        return $this->executado || ($this->fatura !== null && $this->fatura->isFechada($hoje));
    }

    /**
     * Shares that count as executed (see isTravada), in SQL.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function travadas(Builder $query, CarbonImmutable $hoje): void
    {
        $query->where(fn (Builder $travada) => $travada
            ->where('cotas.executado', true)
            ->orWhereHas('fatura', fn (Builder $fatura) => $fatura->fechadas($hoje)));
    }

    /**
     * Shares of a wallet as the main screen and the report see them, joined with their entry and
     * invoice (RN-199, RN-209, RN-226): a card has its own shares; a common wallet has its own shares
     * plus the purchases of the invoices it pays. Dates go through DATA_EFETIVA.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function naCarteiraPorVencimento(Builder $query, Carteira $carteira): void
    {
        $query->join('lancamentos', 'lancamentos.id', '=', 'cotas.lancamento_id')
            ->leftJoin('faturas', 'faturas.id', '=', 'cotas.fatura_id');

        if ($carteira->isCartao()) {
            $query->where('cotas.carteira_id', $carteira->id);

            return;
        }

        $query->where(fn (Builder $daCarteira) => $daCarteira
            ->where(fn (Builder $propria) => $propria->where('cotas.carteira_id', $carteira->id)->whereNull('cotas.fatura_id'))
            ->orWhere('faturas.carteira_vinculada_id', $carteira->id));
    }

    /**
     * Shares of the user in entries authored by someone else (RN-114).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function recebidasPor(Builder $query, User $user): void
    {
        $query->where('user_id', $user->id)
            ->whereHas('lancamento', fn (Builder $lancamento) => $lancamento->where('user_id', '!=', $user->id));
    }
}
