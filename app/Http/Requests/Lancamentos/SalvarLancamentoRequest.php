<?php

namespace App\Http\Requests\Lancamentos;

use App\Enums\EscopoSerie;
use App\Enums\Periodicidade;
use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Lancamento;
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
        $valor = $this->input('valor');

        if (is_string($valor) && preg_match('/^(\d{1,3}(\.\d{3})+|\d+)(,\d{1,2})?$/', $valor) === 1) {
            [$inteiro, $centavos] = array_pad(explode(',', str_replace('.', '', $valor)), 2, '');

            $this->merge(['valor' => (int) $inteiro * 100 + (int) str_pad($centavos, 2, '0')]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoLancamento::class), $this->tipoDaSerie(...)],
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'integer', 'min:1', 'max:'.self::VALOR_MAXIMO, $this->totalDivisivel(...)],
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
        ];
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
     * An installment keeps the type of its series (RN-055, CA-053.5).
     */
    private function tipoDaSerie(string $attribute, mixed $value, Closure $fail): void
    {
        $serie = $this->lancamento()?->parcelamento;

        if ($serie !== null && $serie->tipo->value !== $value) {
            $fail('O tipo de uma série não pode ser alterado.');
        }
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
     * @return array{tipo: TipoLancamento, descricao: string, valor: int, data: string, categoria_id: int, carteira_id: int, observacao: string|null, executado: bool|null}
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
        $escopo = $this->validated('escopo');

        return $escopo === null ? null : EscopoSerie::from($escopo);
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
