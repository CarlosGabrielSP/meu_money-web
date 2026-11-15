<?php

namespace App\Http\Requests\Grupos;

use App\Enums\EscopoSerie;
use App\Models\Cota;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DesagruparRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Shares of the user (RN-125, RN-127).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cotas' => ['required', 'array', 'min:1'],
            'cotas.*' => ['integer', 'distinct', Rule::exists(Cota::class, 'id')->where('user_id', $this->user()?->id)],
            'escopo' => ['nullable', Rule::enum(EscopoSerie::class)],
        ];
    }

    /**
     * @return Collection<int, Cota>
     */
    public function cotas(): Collection
    {
        return Cota::query()->whereKey($this->validated('cotas'))->with('lancamento.parcelamento')->get();
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
        return [
            'cotas.required' => 'Selecione ao menos um item.',
            'cotas.*.exists' => 'Selecione itens seus.',
        ];
    }
}
