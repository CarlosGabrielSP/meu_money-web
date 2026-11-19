<?php

namespace App\Models;

use App\Enums\OrigemQuitacao;
use Carbon\CarbonImmutable;
use Database\Factories\QuitacaoContatoFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Settlement of a contact's part on the author's invoice (RN-202, RN-240, RN-241). One of origin
 * `autor` on a card with a linked wallet is an "a receber" item (RN-216).
 *
 * @property int $id
 * @property int $fatura_id
 * @property int $contato_id
 * @property int $valor
 * @property OrigemQuitacao $origem
 * @property int $pagamento_fatura_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Fatura $fatura
 * @property-read User $contato
 * @property-read PagamentoFatura $pagamento
 */
#[Fillable(['fatura_id', 'contato_id', 'valor', 'origem', 'pagamento_fatura_id'])]
class QuitacaoContato extends Model
{
    /** @use HasFactory<QuitacaoContatoFactory> */
    use HasFactory;

    protected $table = 'quitacoes_contato';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valor' => 'integer',
            'origem' => OrigemQuitacao::class,
        ];
    }

    /**
     * @return BelongsTo<Fatura, $this>
     */
    public function fatura(): BelongsTo
    {
        return $this->belongsTo(Fatura::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function contato(): BelongsTo
    {
        return $this->belongsTo(User::class, 'contato_id');
    }

    /**
     * Payment that made the settlement: the author's, or the contact's in the mirror (RN-240, RN-241).
     *
     * @return BelongsTo<PagamentoFatura, $this>
     */
    public function pagamento(): BelongsTo
    {
        return $this->belongsTo(PagamentoFatura::class, 'pagamento_fatura_id');
    }

    /**
     * @return HasMany<RecebimentoContato, $this>
     */
    public function recebimentos(): HasMany
    {
        return $this->hasMany(RecebimentoContato::class);
    }
}
