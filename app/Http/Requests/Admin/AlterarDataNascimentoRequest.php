<?php

namespace App\Http\Requests\Admin;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AlterarDataNascimentoRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return ['data_nascimento' => $this->dataNascimentoRules()];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['data_nascimento' => 'data de nascimento'];
    }
}
