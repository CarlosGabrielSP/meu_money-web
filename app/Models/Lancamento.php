<?php

namespace App\Models;

use App\Enums\ModoRateio;
use App\Enums\TipoLancamento;
use Carbon\CarbonImmutable;
use Database\Factories\LancamentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $carteira_id
 * @property string $descricao
 * @property TipoLancamento $tipo
 * @property int $valor
 * @property CarbonImmutable $data
 * @property ModoRateio|null $modo_rateio
 * @property int|null $parcelamento_id
 * @property int|null $numero_parcela
 * @property string|null $observacao
 * @property string|null $anexo_caminho
 * @property string|null $anexo_nome
 * @property int|null $criado_por_id
 * @property string|null $criado_por_nome
 * @property int|null $alterado_por_id
 * @property string|null $alterado_por_nome
 * @property CarbonImmutable|null $alterado_em
 * @property int|null $alterado_para_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * Attachment, series and who-acted fields are set by the server only, never mass assigned.
 */
#[Fillable(['descricao', 'tipo', 'valor', 'data', 'modo_rateio', 'observacao'])]
class Lancamento extends Model
{
    /** @use HasFactory<LancamentoFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoLancamento::class,
            'valor' => 'integer',
            'data' => 'date',
            'modo_rateio' => ModoRateio::class,
            'numero_parcela' => 'integer',
            'alterado_em' => 'datetime',
        ];
    }

    /**
     * Author of the entry (RN-079).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Parcelamento, $this>
     */
    public function parcelamento(): BelongsTo
    {
        return $this->belongsTo(Parcelamento::class);
    }

    /**
     * @return HasMany<Cota, $this>
     */
    public function cotas(): HasMany
    {
        return $this->hasMany(Cota::class);
    }

    /**
     * Entries whose author's share is not executed, including entries without an author share
     * (RN-049, RN-056, RN-087). A share in a closed card invoice counts as executed (RN-200).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pendentesDoAutor(Builder $query): void
    {
        $hoje = CarbonImmutable::today();

        $query->whereDoesntHave('cotas', fn (Builder $cotas) => $cotas
            ->whereColumn('cotas.user_id', 'lancamentos.user_id')
            ->travadas($hoje))
            ->where(fn (Builder $semCotaDoAutor) => $semCotaDoAutor
                ->whereHas('cotas', fn (Builder $cotas) => $cotas->whereColumn('cotas.user_id', 'lancamentos.user_id'))
                ->orWhereDoesntHave('cotas', fn (Builder $cotas) => $cotas->travadas($hoje)));
    }

    /**
     * Entries whose author's share is in one of the wallets, or, without an author share, whose entry
     * wallet is one of them (RN-145, RN-278).
     *
     * @param  Builder<self>  $query
     * @param  list<int>  $carteiras
     */
    #[Scope]
    protected function doAutorNasCarteiras(Builder $query, array $carteiras): void
    {
        $query->where(fn (Builder $nasCarteiras) => $nasCarteiras
            ->whereHas('cotas', fn (Builder $cotas) => $cotas
                ->whereColumn('cotas.user_id', 'lancamentos.user_id')
                ->whereIn('cotas.carteira_id', $carteiras))
            ->orWhere(fn (Builder $semCota) => $semCota
                ->whereDoesntHave('cotas', fn (Builder $cotas) => $cotas->whereColumn('cotas.user_id', 'lancamentos.user_id'))
                ->whereIn('lancamentos.carteira_id', $carteiras)));
    }

    /**
     * The owner on whose behalf the member acts as author: the author, when the author's share (or,
     * without one, the entry wallet) is in a wallet delegated to the member (RN-272, RN-145). Null
     * when the entry is not in a wallet delegated to the member.
     */
    public function autorRepresentadoPor(User $membro): ?User
    {
        $carteiraDoAutor = $this->cotas()->where('user_id', $this->user_id)->value('carteira_id') ?? $this->carteira_id;

        return $carteiraDoAutor !== null && $membro->carteirasDelegadas()->whereKey($carteiraDoAutor)->exists()
            ? $this->user
            : null;
    }

    /**
     * The author's share is in a closed card invoice, so the entry is not deleted (RN-200, CA-167.2).
     */
    public function emFaturaFechada(CarbonImmutable $hoje): bool
    {
        return $this->cotas()
            ->where('user_id', $this->user_id)
            ->whereHas('fatura', fn (Builder $fatura) => $fatura->fechadas($hoje))
            ->exists();
    }
}
