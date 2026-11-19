<?php

namespace App\Http\Requests\Lancamentos;

use App\Actions\Rateio\CalcularCotas;
use App\Enums\EscopoSerie;
use App\Enums\ModoRateio;
use App\Enums\Periodicidade;
use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Lancamento;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class SalvarLancamentoRequest extends FormRequest
{
    /**
     * Highest amount accepted, 999.999.999,99 in cents (CA-040.5).
     */
    public const int VALOR_MAXIMO = 99_999_999_999;

    /**
     * Creating is open to any user; updating only to the author (RN-079, CA-041.3).
     */
    public function authorize(): bool
    {
        $lancamento = $this->lancamento();

        return $lancamento === null || ($this->user()?->can('update', $lancamento) ?? false);
    }

    /**
     * Convert an amount typed in pt-BR format ("1.234,56") to cents (RN-012, CA-040.4).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['valor' => self::centavos($this->input('valor'))]);

        $participantes = $this->input('rateio.participantes');

        if (is_array($participantes)) {
            $this->merge(['rateio' => [
                ...(array) $this->input('rateio'),
                'participantes' => array_map(
                    fn (mixed $participante): mixed => is_array($participante) && array_key_exists('valor', $participante)
                        ? [...$participante, 'valor' => self::centavos($participante['valor'])]
                        : $participante,
                    $participantes,
                ),
            ]]);
        }
    }

    /**
     * A pt-BR amount ("1.234,56") as cents; anything else is returned untouched for validation to reject.
     */
    public static function centavos(mixed $valor): mixed
    {
        if (is_string($valor) && preg_match('/^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$/', $valor) === 1) {
            [$inteiro, $centavos] = array_pad(explode(',', str_replace('.', '', $valor)), 2, '');

            return (int) $inteiro * 100 + (int) str_pad($centavos, 2, '0');
        }

        return $valor;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoLancamento::class), $this->tipoDaSerie(...)],
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'integer', 'min:1', 'max:'.self::VALOR_MAXIMO, $this->totalDivisivel(...), $this->faturaFechada(...)],
            'data' => ['required', 'date_format:Y-m-d'],
            'categoria_id' => ['required', 'integer', $this->categoriaCompativel(...)],
            'carteira_id' => [
                'required',
                'integer',
                Rule::exists(Carteira::class, 'id')->where('user_id', $this->user()?->id),
            ],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'executado' => ['nullable', 'boolean'],
            'anexo' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:5120'],
            'repeticao' => ['nullable', Rule::in(['parcelado', 'recorrente']), $this->podeRepetir(...)],
            'periodicidade' => ['nullable', 'required_with:repeticao', Rule::enum(Periodicidade::class)],
            'total_parcelas' => ['nullable', 'required_if:repeticao,parcelado', 'prohibited_if:repeticao,recorrente', 'integer', 'min:2', 'max:360'],
            'modo_valor' => ['nullable', 'required_if:repeticao,parcelado', 'prohibited_if:repeticao,recorrente', Rule::in(['total', 'parcela'])],
            'escopo' => [Rule::requiredIf(fn (): bool => $this->parcela()), 'nullable', Rule::enum(EscopoSerie::class)],
            'dia_vencimento' => ['nullable', 'integer', 'between:1,31'],
            'rateio' => ['nullable', 'array', $this->rateioValido(...), $this->rateioForaDoCartao(...)],
            'rateio.modo' => ['required_with:rateio', Rule::enum(ModoRateio::class)],
            'rateio.participantes' => ['required_with:rateio', 'array', 'min:1'],
            'rateio.participantes.*.user_id' => ['required', 'integer', 'distinct'],
            'rateio.participantes.*.percentual' => ['nullable', 'numeric', 'gt:0', 'max:100', 'decimal:0,2'],
            'rateio.participantes.*.valor' => ['nullable', 'integer', 'min:1', 'max:'.self::VALOR_MAXIMO],
        ];
    }

    /**
     * Until Fase 15 a card purchase is never shared with a contact (RN-231, CA-167.4, CA-167.5).
     */
    private function rateioForaDoCartao(string $attribute, mixed $value, Closure $fail): void
    {
        $carteira = Carteira::query()->whereKey($this->input('carteira_id'))->where('user_id', $this->user()?->id)->first();
        $participantes = data_get($value, 'participantes');
        $autorId = (int) $this->user()?->id;
        $comContato = is_array($participantes) && collect($participantes)->contains(fn (mixed $participante): bool => (int) data_get($participante, 'user_id') !== $autorId);

        if ($carteira?->isCartao() && $comContato) {
            $fail('Ainda não é possível dividir compras do cartão com contatos.');
        }
    }

    /**
     * A purchase in a closed invoice keeps amount, date, wallet and split; only description, category,
     * note and attachment change (RN-200, CA-167.2). Mass scopes keep those fields on the open
     * installment and change only the pending ones (RN-049, CA-167.3).
     */
    private function faturaFechada(string $attribute, mixed $value, Closure $fail): void
    {
        $lancamento = $this->lancamento();

        if ($lancamento === null || ($this->parcela() && $this->input('escopo') !== EscopoSerie::Esta->value)) {
            return;
        }

        $cota = $lancamento->cotas()->where('user_id', $lancamento->user_id)->with('fatura')->first();

        if ($cota === null || $cota->fatura === null || ! $cota->fatura->isFechada(CarbonImmutable::today())) {
            return;
        }

        $mudou = $value !== $lancamento->valor
            || $this->input('data') !== $lancamento->data->toDateString()
            || (int) $this->input('carteira_id') !== $cota->carteira_id
            || $this->input('rateio') !== null;

        if ($mudou) {
            $fail('Fatura fechada: só descrição, categoria, observação e anexo podem mudar.');
        }
    }

    /**
     * A total split into installments needs at least one cent per installment (RN-042, RN-051).
     */
    private function totalDivisivel(string $attribute, mixed $value, Closure $fail): void
    {
        $total = $this->input('total_parcelas');

        if ($this->input('repeticao') === 'parcelado' && $this->input('modo_valor') === 'total'
            && is_numeric($total) && is_int($value) && $value < (int) $total) {
            $fail('O valor total precisa ter pelo menos R$ 0,01 por parcela.');
        }
    }

    /**
     * An installment keeps the type of its series (RN-055, CA-053.5) and a shared entry keeps its
     * type (RN-142, CA-072.6).
     */
    private function tipoDaSerie(string $attribute, mixed $value, Closure $fail): void
    {
        $lancamento = $this->lancamento();

        if ($lancamento?->parcelamento !== null && $lancamento->parcelamento->tipo->value !== $value) {
            $fail('O tipo de uma série não pode ser alterado.');
        } elseif ($lancamento?->modo_rateio !== null && $lancamento->tipo->value !== $value) {
            $fail('O tipo de um lançamento dividido não pode ser alterado.');
        }
    }

    /**
     * A split needs at least one contact besides the author (RN-071, RN-088); new participants must
     * be active contacts, while current ones stay even if no longer contacts (RN-064, RN-109,
     * CA-070.9, CA-063.3). Percentages add up to 100% (RN-074), fixed amounts to the amount of one
     * installment (RN-075, RN-143), and every share is at least one cent (CA-070.13).
     */
    private function rateioValido(string $attribute, mixed $value, Closure $fail): void
    {
        $modo = ModoRateio::tryFrom((string) data_get($value, 'modo'));
        $participantes = data_get($value, 'participantes');
        $valor = $this->input('valor');

        if ($modo === null || ! is_array($participantes) || ! is_int($valor)) {
            return;
        }

        $autorId = (int) $this->user()?->id;
        $ids = array_map(fn (mixed $participante): int => (int) data_get($participante, 'user_id'), $participantes);
        $outros = array_values(array_diff($ids, [$autorId]));

        if ($outros === []) {
            $fail('Inclua pelo menos um contato na divisão.');

            return;
        }

        $permitidos = $this->user()?->consultaDeContatos()->whereKey($outros)->pluck('id')->all() ?? [];
        $atuais = $this->lancamento()?->cotas()->pluck('user_id')->all() ?? [];

        if (array_diff($outros, $permitidos, $atuais) !== []) {
            $fail('Só é possível dividir com contatos ativos.');

            return;
        }

        $base = $this->valorDaParcela($valor);
        $normalizados = array_map(fn (mixed $participante): array => [
            'user_id' => (int) data_get($participante, 'user_id'),
            'percentual' => is_numeric(data_get($participante, 'percentual')) ? (string) data_get($participante, 'percentual') : null,
            'valor' => is_int(data_get($participante, 'valor')) ? (int) data_get($participante, 'valor') : null,
        ], array_values($participantes));

        $calculadora = new CalcularCotas;

        if ($modo === ModoRateio::Percentual) {
            if (in_array(null, array_column($normalizados, 'percentual'), true)
                || array_sum(array_map($calculadora->centesimos(...), array_column($normalizados, 'percentual'))) !== 10_000) {
                $fail('Os percentuais precisam somar 100%.');

                return;
            }
        }

        if ($modo === ModoRateio::Fixo) {
            if (in_array(null, array_column($normalizados, 'valor'), true) || array_sum(array_column($normalizados, 'valor')) !== $base) {
                $fail('Os valores das cotas precisam somar o valor '.($base === $valor ? 'do lançamento.' : 'da parcela.'));

                return;
            }
        }

        $cotas = $calculadora->handle($base, $modo, $normalizados, $autorId);

        if (collect($cotas)->contains(fn (array $cota): bool => $cota['valor'] < 1)) {
            $fail('Cada cota precisa ter pelo menos R$ 0,01.');
        }
    }

    /**
     * Amount of one installment when a series total is split (RN-042), else the amount itself.
     */
    private function valorDaParcela(int $valor): int
    {
        $total = $this->input('total_parcelas');

        return $this->input('repeticao') === 'parcelado' && $this->input('modo_valor') === 'total' && is_numeric($total) && (int) $total > 0
            ? intdiv($valor, (int) $total)
            : $valor;
    }

    /**
     * Only a simple entry becomes a series; an installment cannot be converted again (CA-057.3).
     */
    private function podeRepetir(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && $this->parcela()) {
            $fail('Este lançamento já faz parte de uma série.');
        }
    }

    /**
     * The category must be a system or own category accepting the entry type (RN-033, CA-040.7, CA-041.2).
     */
    private function categoriaCompativel(string $attribute, mixed $value, Closure $fail): void
    {
        $categoria = Categoria::query()->visiveisPara($this->user())->whereKey($value)->first();

        if ($categoria === null) {
            $fail('Selecione uma categoria válida.');

            return;
        }

        $tipo = TipoLancamento::tryFrom((string) $this->input('tipo'));

        if ($tipo !== null && ! $categoria->tipo->aceita($tipo)) {
            $fail('A categoria não aceita lançamentos deste tipo.');
        }
    }

    /**
     * @return array{tipo: TipoLancamento, descricao: string, valor: int, data: string, categoria_id: int, carteira_id: int, observacao: string|null, executado: bool|null, rateio: array{modo: ModoRateio, participantes: list<array{user_id: int, percentual: string|null, valor: int|null}>}|null}
     */
    public function dados(): array
    {
        $executado = $this->validated('executado');

        return [
            'tipo' => TipoLancamento::from($this->validated('tipo')),
            'descricao' => $this->validated('descricao'),
            'valor' => (int) $this->validated('valor'),
            'data' => $this->validated('data'),
            'categoria_id' => (int) $this->validated('categoria_id'),
            'carteira_id' => (int) $this->validated('carteira_id'),
            'observacao' => $this->validated('observacao'),
            'executado' => $executado === null ? null : filter_var($executado, FILTER_VALIDATE_BOOLEAN),
            'rateio' => $this->rateio(),
        ];
    }

    /**
     * Split chosen in the form, in the order the participants were included (RN-073), or null.
     *
     * @return array{modo: ModoRateio, participantes: list<array{user_id: int, percentual: string|null, valor: int|null}>}|null
     */
    public function rateio(): ?array
    {
        $rateio = $this->validated('rateio');

        if (! is_array($rateio)) {
            return null;
        }

        return [
            'modo' => ModoRateio::from($rateio['modo']),
            'participantes' => array_values(array_map(fn (array $participante): array => [
                'user_id' => (int) $participante['user_id'],
                'percentual' => isset($participante['percentual']) ? (string) $participante['percentual'] : null,
                'valor' => isset($participante['valor']) ? (int) $participante['valor'] : null,
            ], $rateio['participantes'])),
        ];
    }

    /**
     * Repetition chosen in the form, or null for a simple entry (RF-050, RF-051, RF-057).
     *
     * @return array{recorrente: bool, periodicidade: Periodicidade, total_parcelas: int|null, valor_total: bool}|null
     */
    public function repeticao(): ?array
    {
        $repeticao = $this->validated('repeticao');

        if ($repeticao === null) {
            return null;
        }

        $total = $this->validated('total_parcelas');

        return [
            'recorrente' => $repeticao === 'recorrente',
            'periodicidade' => Periodicidade::from($this->validated('periodicidade')),
            'total_parcelas' => $total === null ? null : (int) $total,
            'valor_total' => $this->validated('modo_valor') === 'total',
        ];
    }

    public function escopo(): ?EscopoSerie
    {
        return $this->enum('escopo', EscopoSerie::class);
    }

    public function diaVencimento(): ?int
    {
        $dia = $this->validated('dia_vencimento');

        return $dia === null ? null : (int) $dia;
    }

    public function parcela(): bool
    {
        return $this->lancamento()?->parcelamento_id !== null;
    }

    public function anexo(): ?UploadedFile
    {
        $anexo = $this->file('anexo');

        return $anexo instanceof UploadedFile ? $anexo : null;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valor.integer' => 'Informe um valor válido, como 1.234,56.',
            'valor.min' => 'O valor deve ser maior que zero.',
            'valor.max' => 'O valor máximo é 999.999.999,99.',
            'carteira_id.exists' => 'Selecione uma carteira válida.',
            'anexo.mimes' => 'O anexo deve ser PDF, JPG, PNG ou WEBP.',
            'anexo.max' => 'O anexo deve ter no máximo 5 MB.',
            'total_parcelas.min' => 'A série deve ter pelo menos 2 parcelas.',
            'total_parcelas.max' => 'A série pode ter no máximo 360 parcelas.',
            'total_parcelas.prohibited_if' => 'Série recorrente não tem total de parcelas.',
            'modo_valor.prohibited_if' => 'Série recorrente aceita só o valor da parcela.',
            'escopo.required' => 'Escolha quais parcelas alterar.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'descricao' => 'descrição',
            'categoria_id' => 'categoria',
            'carteira_id' => 'carteira',
            'observacao' => 'observação',
            'repeticao' => 'repetição',
            'total_parcelas' => 'número de parcelas',
            'modo_valor' => 'tipo de valor',
            'dia_vencimento' => 'dia de vencimento',
        ];
    }

    private function lancamento(): ?Lancamento
    {
        $lancamento = $this->route('lancamento');

        return $lancamento instanceof Lancamento ? $lancamento : null;
    }
}
