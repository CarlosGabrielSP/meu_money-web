<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\FaturaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One invoice per card cycle (RN-192, RN-193, RN-229).
 *
 * @property int $id
 * @property int $carteira_id
 * @property CarbonImmutable $referencia first day of the due month
 * @property CarbonImmutable $fechamento
 * @property CarbonImmutable $vencimento
 * @property int|null $carteira_vinculada_id
 * @property bool $datas_editadas
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['carteira_id', 'referencia', 'fechamento', 'vencimento', 'carteira_vinculada_id'])]
class Fatura extends Model
{
    /** @use HasFactory<FaturaFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'datas_editadas' => false,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'referencia' => 'date',
            'fechamento' => 'date',
            'vencimento' => 'date',
            'datas_editadas' => 'boolean',
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
     * @return BelongsTo<Carteira, $this>
     */
    public function carteiraVinculada(): BelongsTo
    {
        return $this->belongsTo(Carteira::class, 'carteira_vinculada_id');
    }

    /**
     * @return HasMany<Cota, $this>
     */
    public function cotas(): HasMany
    {
        return $this->hasMany(Cota::class);
    }

    /**
     * @return HasMany<PagamentoFatura, $this>
     */
    public function pagamentos(): HasMany
    {
        return $this->hasMany(PagamentoFatura::class);
    }

    /**
     * Closed invoices: closing before today (RN-200, RN-221). Dates are compared with bare "Y-m-d"
     * strings, since the date cast stores "Y-m-d 00:00:00".
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function fechadas(Builder $query, CarbonImmutable $hoje): void
    {
        $query->where('faturas.fechamento', '<', $hoje->toDateString());
    }

    /**
     * Open and future invoices (RN-193, RN-220).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function naoFechadas(Builder $query, CarbonImmutable $hoje): void
    {
        $query->where('faturas.fechamento', '>=', $hoje->toDateString());
    }

    /**
     * A closed invoice has its closing before today and never changes (RN-200, RN-221).
     */
    public function isFechada(CarbonImmutable $hoje): bool
    {
        return $this->fechamento->lessThan($hoje->startOfDay());
    }
}
