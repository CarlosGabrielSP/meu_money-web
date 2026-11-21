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
     * wallet. A member marks it when that wallet is delegated to them (RN-281, RN-305, CA-208.4).
     */
    public function authorize(): bool
    {
        $quitacao = $this->route('quitacaoContato');
        $user = $this->user();

        if ($user === null || ! $quitacao instanceof QuitacaoContato || $quitacao->origem !== OrigemQuitacao::Autor) {
            return false;
        }

        $carteiraId = $quitacao->pagamento->carteira_id;

        return $carteiraId !== null
            && ($user->can('update', $quitacao->fatura->carteira)
                || $user->carteirasDelegadas()->whereKey($carteiraId)->exists());
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
