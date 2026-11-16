<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ResponderModoSimplesRequest extends FormRequest
{
    /**
     * Answer of the first access question: "Sim" or "Não, agora não" (RN-156).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['modo_simples' => ['required', 'boolean']];
    }
}
