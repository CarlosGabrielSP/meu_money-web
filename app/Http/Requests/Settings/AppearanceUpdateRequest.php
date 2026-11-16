<?php

namespace App\Http\Requests\Settings;

use App\Enums\Paleta;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AppearanceUpdateRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Each control of the page saves one setting: the palette (RN-152) or the simple mode (RN-157).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'paleta' => ['required_without:modo_simples', Rule::enum(Paleta::class)],
            'modo_simples' => ['required_without:paleta', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['modo_simples' => 'modo simples'];
    }
}
