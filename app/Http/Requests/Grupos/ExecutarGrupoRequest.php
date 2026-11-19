<?php

namespace App\Http\Requests\Grupos;

use App\Enums\TipoCarteira;
use App\Models\Cota;
use App\Models\Grupo;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExecutarGrupoRequest extends FormRequest
{
    /**
     * Only the owner (CA-093.5).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->grupo()) ?? false;
    }

    /**
     * The shares of the group visible in the month, sent by the list (RN-128, RN-129). A group in a
     * card has no status (RN-211).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'executado' => ['required', 'boolean', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->grupo()->carteira()->where('tipo', TipoCarteira::Cartao->value)->exists()) {
                    $fail('Grupo no cartão não tem pago: a fatura controla o pagamento.');
                }
            }],
            'cotas' => ['required', 'array', 'min:1'],
            'cotas.*' => ['integer', 'distinct', Rule::exists(Cota::class, 'id')->where('grupo_id', $this->grupo()->id)],
        ];
    }

    /**
     * @return list<int>
     */
    public function cotas(): array
    {
        return array_values(array_map(intval(...), $this->validated('cotas')));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['cotas.*.exists' => 'Algum item não está mais no grupo. Atualize a tela.'];
    }

    public function grupo(): Grupo
    {
        /** @var Grupo $grupo */
        $grupo = $this->route('grupo');

        return $grupo;
    }
}
