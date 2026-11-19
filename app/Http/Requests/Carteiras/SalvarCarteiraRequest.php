<?php

namespace App\Http\Requests\Carteiras;

use App\Enums\CorCarteira;
use App\Enums\IconeCarteira;
use App\Enums\TipoCarteira;
use App\Models\Cartao;
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
        $carteira = $this->carteiraAtual();

        return $carteira === null || ($this->user()?->can('update', $carteira) ?? false);
    }

    /**
     * The type is chosen only on create and never changes (RN-190, CA-161.1). Card fields apply only
     * to a card (CA-160.5): due day 1 to 31, closing 1 to 25 days before it, and an optional linked
     * wallet that is a common wallet of the user (RN-191, CA-160.3, CA-160.4).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $cartao = $this->isCartao() ? [] : ['exclude'];

        return [
            'nome' => ['required', 'string', 'max:100'],
            'cor' => ['nullable', Rule::enum(CorCarteira::class)],
            'icone' => ['nullable', Rule::enum(IconeCarteira::class)],
            'tipo' => $this->carteiraAtual() === null ? ['nullable', Rule::enum(TipoCarteira::class)] : ['exclude'],
            'dia_vencimento' => [...$cartao, 'required', 'integer', 'between:1,31'],
            'dias_fechamento' => [...$cartao, 'nullable', 'integer', 'between:1,25'],
            'carteira_vinculada_id' => [
                ...$cartao,
                'nullable',
                'integer',
                Rule::exists(Carteira::class, 'id')
                    ->where('user_id', $this->user()?->id)
                    ->where('tipo', TipoCarteira::Comum->value),
            ],
        ];
    }

    /**
     * Validated wallet data. A missing color or icon gets the default on create, `credit-card` for a
     * card (RN-009, RN-191, CA-021.1), and keeps the current value on update (CA-022.1).
     *
     * @return array{nome: string, tipo: TipoCarteira, cor: CorCarteira|string, icone: IconeCarteira|string}
     */
    public function dados(): array
    {
        $atual = $this->carteiraAtual();
        $tipo = $atual->tipo ?? $this->tipo();

        return [
            'nome' => $this->validated('nome'),
            'tipo' => $tipo,
            'cor' => $this->validated('cor') ?? $atual->cor ?? CorCarteira::Blue,
            'icone' => $this->validated('icone') ?? $atual->icone
                ?? ($tipo === TipoCarteira::Cartao ? IconeCarteira::CreditCard : IconeCarteira::Wallet),
        ];
    }

    /**
     * Card data, or null for a common wallet. A missing closing keeps the current one, or the default
     * of 10 days on create (RN-191, RN-212, DEC-085).
     *
     * @return array{dia_vencimento: int, dias_fechamento: int, carteira_vinculada_id: int|null}|null
     */
    public function cartao(): ?array
    {
        if (! $this->isCartao()) {
            return null;
        }

        $vinculada = $this->validated('carteira_vinculada_id');
        $fechamento = $this->validated('dias_fechamento');

        return [
            'dia_vencimento' => (int) $this->validated('dia_vencimento'),
            'dias_fechamento' => $fechamento !== null
                ? (int) $fechamento
                : ($this->carteiraAtual()?->cartao->dias_fechamento ?? Cartao::DIAS_FECHAMENTO_PADRAO),
            'carteira_vinculada_id' => $vinculada === null ? null : (int) $vinculada,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'icone' => 'ícone',
            'dia_vencimento' => 'dia do vencimento',
            'dias_fechamento' => 'dias de fechamento',
            'carteira_vinculada_id' => 'carteira que paga a fatura',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dia_vencimento.between' => 'O dia do vencimento vai de 1 a 31.',
            'dias_fechamento.between' => 'O fechamento vai de 1 a 25 dias antes do vencimento.',
            'carteira_vinculada_id.exists' => 'Escolha uma carteira comum sua para pagar a fatura.',
        ];
    }

    private function isCartao(): bool
    {
        return ($this->carteiraAtual()->tipo ?? $this->tipo()) === TipoCarteira::Cartao;
    }

    private function tipo(): TipoCarteira
    {
        return TipoCarteira::tryFrom((string) $this->input('tipo')) ?? TipoCarteira::Comum;
    }

    private function carteiraAtual(): ?Carteira
    {
        $carteira = $this->route('carteira');

        return $carteira instanceof Carteira ? $carteira : null;
    }
}
