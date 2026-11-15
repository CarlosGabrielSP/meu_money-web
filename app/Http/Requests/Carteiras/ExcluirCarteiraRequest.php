<?php

namespace App\Http\Requests\Carteiras;

use App\Models\Carteira;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExcluirCarteiraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', $this->route('carteira')) ?? false;
    }

    /**
     * Destination must be another wallet of the same user (RN-006, CA-024.7).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Carteira $carteira */
        $carteira = $this->route('carteira');

        return [
            'modo' => ['required', Rule::in(['mover', 'excluir'])],
            'destino_id' => [
                'exclude_unless:modo,mover',
                'required',
                'integer',
                Rule::exists(Carteira::class, 'id')
                    ->where('user_id', $this->user()?->id)
                    ->whereNot('id', $carteira->id),
            ],
        ];
    }

    public function destino(): ?Carteira
    {
        if ($this->validated('modo') !== 'mover') {
            return null;
        }

        return Carteira::query()->whereKey($this->validated('destino_id'))->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['destino_id' => 'carteira de destino'];
    }
}
