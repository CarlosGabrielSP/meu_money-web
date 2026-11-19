<?php

namespace App\Http\Requests\Carteiras;

use App\Enums\TipoCarteira;
use App\Models\Carteira;
use Closure;
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
     * Destination must be another wallet of the same user (RN-006, CA-024.7); a common wallet moves
     * only to another common wallet, a card to any other wallet except a mirror (RN-233, RN-262, DEC-087).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Carteira $carteira */
        $carteira = $this->route('carteira');

        return [
            'modo' => ['required', Rule::in(['mover', 'excluir']), $this->espelhoSemPartes(...)],
            'destino_id' => [
                'exclude_unless:modo,mover',
                'required',
                'integer',
                Rule::exists(Carteira::class, 'id')
                    ->where('user_id', $this->user()?->id)
                    ->whereNot('id', $carteira->id)
                    ->when(! $carteira->isCartao(), fn ($rule) => $rule->where('tipo', TipoCarteira::Comum->value)),
                $this->foraDoEspelho(...),
            ],
        ];
    }

    /**
     * The contact never deletes a mirror with parts (RN-237, CA-182.3).
     */
    private function espelhoSemPartes(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var Carteira $carteira */
        $carteira = $this->route('carteira');
        $autor = Carteira::autorDoEspelho($carteira->id);

        if ($autor !== null && $carteira->cotas()->exists()) {
            $fail("Este cartão tem compras divididas com {$autor}.");
        }
    }

    /**
     * A mirror receives only the author's shared purchases (RN-262, CA-182.9).
     */
    private function foraDoEspelho(string $attribute, mixed $value, Closure $fail): void
    {
        $autor = Carteira::autorDoEspelho($value);

        if ($autor !== null) {
            $fail("Este cartão recebe só as compras divididas com {$autor}.");
        }
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
