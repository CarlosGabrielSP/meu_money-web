<?php

namespace App\Http\Requests\Lancamentos;

use App\Enums\EscopoSerie;
use App\Models\Lancamento;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExcluirLancamentoRequest extends FormRequest
{
    /**
     * Only the author deletes the entry (CA-043.2).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('delete', $this->lancamento()) ?? false;
    }

    /**
     * An installment asks which installments to delete (RN-048, RF-054).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'escopo' => [Rule::requiredIf(fn (): bool => $this->lancamento()->parcelamento_id !== null), 'nullable', Rule::enum(EscopoSerie::class)],
        ];
    }

    public function escopo(): ?EscopoSerie
    {
        return $this->enum('escopo', EscopoSerie::class);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['escopo.required' => 'Escolha quais parcelas excluir.'];
    }

    private function lancamento(): Lancamento
    {
        /** @var Lancamento $lancamento */
        $lancamento = $this->route('lancamento');

        return $lancamento;
    }
}
