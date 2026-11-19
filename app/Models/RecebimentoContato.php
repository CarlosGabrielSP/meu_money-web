<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\RecebimentoContatoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Receipt of an "a receber" item: automatic, from the contact's mirror payment, or manual without a
 * payment (RN-217, RN-242).
 *
 * @property int $id
 * @property int $quitacao_contato_id
 * @property int $valor
 * @property CarbonImmutable $data
 * @property int|null $pagamento_fatura_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read QuitacaoContato $quitacao
 */
#[Fillable(['quitacao_contato_id', 'valor', 'data', 'pagamento_fatura_id'])]
class RecebimentoContato extends Model
{
    /** @use HasFactory<RecebimentoContatoFactory> */
    use HasFactory;

    protected $table = 'recebimentos_contato';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'integer',
            'data' => 'date',
        ];
    }

    /**
     * @return BelongsTo<QuitacaoContato, $this>
     */
    public function quitacao(): BelongsTo
    {
        return $this->belongsTo(QuitacaoContato::class, 'quitacao_contato_id');
    }

    /**
     * The contact's mirror payment of an automatic receipt; null for a manual one.
     *
     * @return BelongsTo<PagamentoFatura, $this>
     */
    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(PagamentoFatura::class, 'pagamento_fatura_id');
    }
}
