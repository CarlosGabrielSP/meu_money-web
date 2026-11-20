<?php

namespace App\Http\Requests\Cartoes;

use App\Http\Requests\Lancamentos\SalvarLancamentoRequest;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class PagarFaturaRequest extends FormRequest
{
    /**
     * Only the card owner pays (CA-165.6); the route resolves only cards.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('carteira')) ?? false;
    }

    /**
     * Amount in pt-BR format, as in the entry form (RN-012).
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['valor' => SalvarLancamentoRequest::centavos($this->input('valor'))]);
    }

    /**
     * Amount above zero, no minimum; date until today, never future (RN-195, RN-222, CA-165.2). With
     * a marked contact the typed amount may be empty or zero; the payment, typed amount plus the
     * marked parts, is checked above zero when paying (RN-264).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $comContatos = array_filter((array) $this->input('contatos', [])) !== [];

        return [
            'valor' => [$comContatos ? 'nullable' : 'required', 'integer', $comContatos ? 'min:0' : 'min:1', 'max:'.SalvarLancamentoRequest::VALOR_MAXIMO],
            'data' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.CarbonImmutable::today()->toDateString()],
            'contatos' => ['nullable', 'array'],
            'contatos.*' => ['integer', 'distinct'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'valor.required' => 'Informe um valor maior que zero.',
            'valor.integer' => 'Informe um valor válido, como 1.234,56.',
            'valor.min' => 'Informe um valor maior que zero.',
            'valor.max' => 'O valor máximo é 999.999.999,99.',
            'data.before_or_equal' => 'A data não pode ser futura.',
        ];
    }
}
