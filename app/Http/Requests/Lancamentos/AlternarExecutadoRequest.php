<?php

namespace App\Http\Requests\Lancamentos;

use App\Enums\TipoCarteira;
use App\Models\Cota;
use Closure;
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
     * A share in a card has no status: the invoice controls the payment (RN-194, CA-167.1).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'executado' => ['required', 'boolean', function (string $attribute, mixed $value, Closure $fail): void {
                $cota = $this->route('cota');

                if ($cota instanceof Cota && $cota->carteira()->where('tipo', TipoCarteira::Cartao->value)->exists()) {
                    $fail('Compra no cartão não tem pago: a fatura controla o pagamento.');
                }
            }],
        ];
    }
}
