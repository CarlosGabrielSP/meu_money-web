<?php

namespace App\Http\Requests\Rateio;

use App\Enums\EscopoSerie;
use App\Models\Cota;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SairDoRateioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('sair', $this->cota()) ?? false;
    }

    /**
     * An installment asks which installments to leave (CA-073.4).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'escopo' => [Rule::requiredIf(fn (): bool => $this->cota()->lancamento->parcelamento_id !== null), 'nullable', Rule::enum(EscopoSerie::class)],
        ];
    }

    public function escopo(): EscopoSerie
    {
        return $this->enum('escopo', EscopoSerie::class) ?? EscopoSerie::Esta;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['escopo.required' => 'Escolha de quais parcelas sair.'];
    }

    public function cota(): Cota
    {
        /** @var Cota $cota */
        $cota = $this->route('cota');

        return $cota;
    }
}
