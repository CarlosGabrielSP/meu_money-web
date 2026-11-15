<?php

namespace App\Http\Requests\Series;

use App\Models\Parcelamento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AlterarTotalParcelasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->serie()) ?? false;
    }

    /**
     * Only installment series have a total (RN-058, CA-050.7 limits).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'total_parcelas' => ['required', 'integer', 'min:2', 'max:360', $this->parcelada(...)],
        ];
    }

    private function parcelada(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->serie()->isRecorrente()) {
            $fail('Série recorrente não tem total de parcelas.');
        }
    }

    public function total(): int
    {
        return (int) $this->validated('total_parcelas');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'total_parcelas.min' => 'A série deve ter pelo menos 2 parcelas.',
            'total_parcelas.max' => 'A série pode ter no máximo 360 parcelas.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['total_parcelas' => 'número de parcelas'];
    }

    private function serie(): Parcelamento
    {
        /** @var Parcelamento $serie */
        $serie = $this->route('parcelamento');

        return $serie;
    }
}
