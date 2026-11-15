<?php

namespace App\Http\Requests\Carteiras;

use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Models\Carteira;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarCarteiraRequest extends FormRequest
{
    /**
     * Creating is open to any user; updating only to the owner (RN-001).
     */
    public function authorize(): bool
    {
        $carteira = $this->route('carteira');

        return ! $carteira instanceof Carteira || ($this->user()?->can('update', $carteira) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'cor' => ['nullable', Rule::enum(CorCarteira::class)],
            'icone' => ['nullable', Rule::enum(IconeCarteira::class)],
        ];
    }

    /**
     * Validated data. A missing color or icon gets the default on create (RN-009, CA-021.1)
     * and keeps the current value on update (CA-022.1).
     *
     * @return array{nome: string, cor: CorCarteira|string, icone: IconeCarteira|string}
     */
    public function dados(): array
    {
        $carteira = $this->route('carteira');
        $atual = $carteira instanceof Carteira ? $carteira : null;

        return [
            'nome' => $this->validated('nome'),
            'cor' => $this->validated('cor') ?? $atual->cor ?? CorCarteira::Blue,
            'icone' => $this->validated('icone') ?? $atual->icone ?? IconeCarteira::Wallet,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['icone' => 'ícone'];
    }
}
