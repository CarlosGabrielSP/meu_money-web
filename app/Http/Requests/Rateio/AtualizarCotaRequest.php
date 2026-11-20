<?php

namespace App\Http\Requests\Rateio;

use App\Models\Carteira;
use App\Models\Categoria;
use App\Models\Cota;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AtualizarCotaRequest extends FormRequest
{
    /**
     * Only the participant who owns the share (CA-071.3).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('participar', $this->cota()) ?? false;
    }

    /**
     * Own wallet and a category visible to the participant that accepts the entry type (RN-080, RN-033).
     * A share never moves into a mirror; a share already in one may keep it (RN-262) and never leaves
     * it (RN-205, RN-266).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'carteira_id' => ['required', 'integer', Rule::exists(Carteira::class, 'id')->where('user_id', $this->user()?->id), $this->foraDeOutroEspelho(...)],
            'categoria_id' => ['required', 'integer', $this->categoriaCompativel(...)],
            'executado' => ['required', 'boolean'],
        ];
    }

    private function foraDeOutroEspelho(string $attribute, mixed $value, Closure $fail): void
    {
        if ((int) $value === $this->cota()->carteira_id) {
            return;
        }

        $autorDaCota = Carteira::autorDoEspelho($this->cota()->carteira_id);

        if ($autorDaCota !== null) {
            $fail("Este cartão tem compras divididas com {$autorDaCota}.");

            return;
        }

        $autor = Carteira::autorDoEspelho($value);

        if ($autor !== null) {
            $fail("Este cartão recebe só as compras divididas com {$autor}.");
        }
    }

    private function categoriaCompativel(string $attribute, mixed $value, Closure $fail): void
    {
        $categoria = Categoria::query()->visiveisPara($this->user())->whereKey($value)->first();

        if ($categoria === null || ! $categoria->tipo->aceita($this->cota()->lancamento->tipo)) {
            $fail('Selecione uma categoria válida para este lançamento.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['carteira_id.exists' => 'Selecione uma carteira válida.'];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['carteira_id' => 'carteira', 'categoria_id' => 'categoria'];
    }

    public function cota(): Cota
    {
        /** @var Cota $cota */
        $cota = $this->route('cota');

        return $cota;
    }
}
