<?php

namespace App\Http\Requests\Lancamentos;

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
            'tipo' => ['required', Rule::enum(TipoLancamento::class)],
            'descricao' => ['required', 'string', 'max:255'],
            'valor' => ['required', 'integer', 'min:1', 'max:'.self::VALOR_MAXIMO],
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
        ];
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
        ];
    }

    private function lancamento(): ?Lancamento
    {
        $lancamento = $this->route('lancamento');

        return $lancamento instanceof Lancamento ? $lancamento : null;
    }
}
