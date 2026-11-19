<?php

namespace App\Http\Requests\Cartoes;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class EditarDatasFaturaRequest extends FormRequest
{
    /**
     * Only the card owner (RN-001); the route resolves only cards.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->route('carteira')) ?? false;
    }

    /**
     * The limits between invoices are checked by EditarDatasFatura (RN-193).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fechamento' => ['required', 'date_format:Y-m-d'],
            'vencimento' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
