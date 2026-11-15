<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * Confirmation is the password or, for accounts without password, the user's own email (RN-112).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if ($this->campoConfirmacao() === 'confirmacao_email') {
            return ['confirmacao_email' => ['required', 'string', Rule::in([$this->user()?->email])]];
        }

        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * Field that confirms the deletion: the password, or the email for accounts without password.
     */
    public function campoConfirmacao(): string
    {
        return $this->user()?->password === null ? 'confirmacao_email' : 'password';
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['confirmacao_email.in' => 'Digite exatamente o seu e-mail para confirmar.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['confirmacao_email' => 'confirmação de e-mail'];
    }
}
