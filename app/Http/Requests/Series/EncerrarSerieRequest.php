<?php

namespace App\Http\Requests\Series;

use App\Models\Parcelamento;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EncerrarSerieRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->serie()) ?? false;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'data' => ['required', 'date_format:Y-m-d', $this->recorrente(...)],
        ];
    }

    /**
     * Only an open recurring series is ended, on or after its first installment (RN-046). Ending
     * again later would leave a gap, because removed installments are never regenerated (RN-057).
     */
    private function recorrente(string $attribute, mixed $value, Closure $fail): void
    {
        $serie = $this->serie();

        if (! $serie->isRecorrente()) {
            $fail('Só série recorrente pode ser encerrada.');
        } elseif ($serie->encerrado_em !== null) {
            $fail('A série já está encerrada.');
        } elseif (is_string($value) && $value < $serie->data_inicio->toDateString()) {
            $fail('A data de encerramento não pode ser anterior ao início da série.');
        }
    }

    public function dataEncerramento(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('data'));
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['data' => 'data de encerramento'];
    }

    private function serie(): Parcelamento
    {
        /** @var Parcelamento $serie */
        $serie = $this->route('parcelamento');

        return $serie;
    }
}
