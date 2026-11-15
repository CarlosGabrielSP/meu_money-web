<?php

namespace App\Http\Requests\Contatos;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ConvidarContatoRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255', $this->naoProprio(...)],
        ];
    }

    /**
     * Inviting oneself is the only case that shows an error (RN-065, CA-060.4).
     */
    private function naoProprio(string $attribute, mixed $value, Closure $fail): void
    {
        if (Str::lower((string) $value) === Str::lower((string) $this->user()?->email)) {
            $fail('Você não pode convidar a si mesmo.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['email' => 'e-mail'];
    }
}
