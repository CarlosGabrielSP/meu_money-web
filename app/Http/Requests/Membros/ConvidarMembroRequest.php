<?php

namespace App\Http\Requests\Membros;

use App\Models\Carteira;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ConvidarMembroRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('gerenciarMembros', $this->route('carteira')) ?? false;
    }

    /**
     * The invited user is an active accepted contact of the owner (RN-269, RN-270, CA-200.2); only a
     * common wallet is delegated (CA-200.3).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'carteira' => [$this->carteiraComum(...)],
            'user_id' => ['required', 'integer', $this->contatoDoDono(...)],
        ];
    }

    /**
     * The route wallet is validated as a field, so the error goes with the form.
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return [...$this->all(), 'carteira' => $this->route('carteira')];
    }

    private function carteiraComum(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof Carteira && $value->isCartao()) {
            $fail('Só carteira comum pode ser delegada.');
        }
    }

    private function contatoDoDono(string $attribute, mixed $value, Closure $fail): void
    {
        /** @var User $dono */
        $dono = $this->user();

        if (! is_numeric($value) || ! $dono->consultaDeContatos()->whereKey((int) $value)->exists()) {
            $fail('Escolha um dos seus contatos.');
        }
    }

    public function convidado(): User
    {
        return User::query()->findOrFail($this->integer('user_id'));
    }
}
