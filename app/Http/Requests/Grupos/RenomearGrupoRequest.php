<?php

namespace App\Http\Requests\Grupos;

use App\Models\Grupo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RenomearGrupoRequest extends FormRequest
{
    /**
     * Only the owner (CA-093.5).
     */
    public function authorize(): bool
    {
        $grupo = $this->route('grupo');

        return $grupo instanceof Grupo && ($this->user()?->can('update', $grupo) ?? false);
    }

    /**
     * Required, up to 100 characters, may repeat (RN-150).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['nome' => 'nome do grupo'];
    }
}
