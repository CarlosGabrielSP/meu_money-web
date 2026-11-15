<?php

namespace App\Models;

use App\Enums\ModoRateio;
use App\Enums\Periodicidade;
use App\Enums\TipoLancamento;
use Carbon\CarbonImmutable;
use Database\Factories\ParcelamentoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int $carteira_id
 * @property int $categoria_id
 * @property int|null $grupo_id
 * @property string $descricao
 * @property TipoLancamento $tipo
 * @property int $valor_parcela
 * @property int|null $total_parcelas
 * @property Periodicidade $periodicidade
 * @property CarbonImmutable $data_inicio
 * @property int $dia_vencimento
 * @property ModoRateio|null $modo_rateio
 * @property CarbonImmutable $gerado_ate
 * @property CarbonImmutable|null $encerrado_em
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'carteira_id', 'categoria_id', 'grupo_id', 'descricao', 'tipo', 'valor_parcela', 'total_parcelas',
    'periodicidade', 'data_inicio', 'dia_vencimento', 'modo_rateio', 'gerado_ate', 'encerrado_em',
])]
class Parcelamento extends Model
{
    /** @use HasFactory<ParcelamentoFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tipo' => TipoLancamento::class,
            'valor_parcela' => 'integer',
            'total_parcelas' => 'integer',
            'periodicidade' => Periodicidade::class,
            'data_inicio' => 'date',
            'dia_vencimento' => 'integer',
            'modo_rateio' => ModoRateio::class,
            'gerado_ate' => 'date',
            'encerrado_em' => 'date',
        ];
    }

    /**
     * A series without a fixed number of installments is recurring (RN-040).
     */
    public function isRecorrente(): bool
    {
        return $this->total_parcelas === null;
    }

    /**
     * Due date of installment number n (RN-041, RN-052, RN-054): the month advances from the first
     * installment by the periodicity and the day is the due day, or the last day of a shorter month.
     */
    public function dataDaParcela(int $numero): CarbonImmutable
    {
        $mes = $this->data_inicio->startOfMonth()->addMonthsNoOverflow(($numero - 1) * $this->periodicidade->meses());

        return self::noDia($mes, $this->dia_vencimento);
    }

    /**
     * The given day in the month of the date, or the last day of a shorter month (RN-052, RN-054).
     */
    public static function noDia(CarbonImmutable $data, int $dia): CarbonImmutable
    {
        return $data->setDay(min($dia, $data->daysInMonth));
    }

    /**
     * Number of the installment due in the month of the given date.
     */
    public function numeroDaData(CarbonImmutable $data): int
    {
        $meses = (int) $this->data_inicio->startOfMonth()->diffInMonths($data->startOfMonth());

        return intdiv($meses, $this->periodicidade->meses()) + 1;
    }

    /**
     * Last installment number due on or before the limit (0 when even the first is later).
     */
    public function ultimoNumeroAte(CarbonImmutable $limite): int
    {
        if ($this->data_inicio->greaterThan($limite)) {
            return 0;
        }

        $numero = $this->numeroDaData($limite);

        return $this->dataDaParcela($numero)->greaterThan($limite) ? $numero - 1 : $numero;
    }

    /**
     * Recurring series keep installments generated up to today plus 12 months (RN-045).
     */
    public static function horizonteRecorrente(): CarbonImmutable
    {
        return CarbonImmutable::today()->addMonthsNoOverflow(12);
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
     * @return HasMany<Lancamento, $this>
     */
    public function lancamentos(): HasMany
    {
        return $this->hasMany(Lancamento::class);
    }

    /**
     * @return HasMany<ParcelamentoCota, $this>
     */
    public function cotas(): HasMany
    {
        return $this->hasMany(ParcelamentoCota::class);
    }
}
