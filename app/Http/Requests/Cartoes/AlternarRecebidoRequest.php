<?php

namespace App\Http\Requests\Cartoes;

use App\Enums\OrigemQuitacao;
use App\Models\QuitacaoContato;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AlternarRecebidoRequest extends FormRequest
{
    /**
     * Only the author of a settlement of origin `autor` from a linked card has the "a receber" item
     * (RN-216, RN-242): the owner of the card of the settled invoice, with a payment that left a
     * wallet.
     */
    public function authorize(): bool
    {
        $quitacao = $this->route('quitacaoContato');

        return $quitacao instanceof QuitacaoContato
            && $quitacao->origem === OrigemQuitacao::Autor
            && $quitacao->pagamento->carteira_id !== null
            && ($this->user()?->can('update', $quitacao->fatura->carteira) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recebido' => ['required', 'boolean'],
        ];
    }
}
