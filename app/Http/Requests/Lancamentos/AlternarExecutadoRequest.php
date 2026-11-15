<?php

namespace App\Http\Requests\Lancamentos;

use App\Models\Cota;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AlternarExecutadoRequest extends FormRequest
{
    /**
     * Only the owner of the share changes its status (CA-042.3).
     */
    public function authorize(): bool
    {
        $cota = $this->route('cota');

        return $cota instanceof Cota && ($this->user()?->can('update', $cota) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'executado' => ['required', 'boolean'],
        ];
    }
}
