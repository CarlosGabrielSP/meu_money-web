<?php

namespace App\Http\Requests\Grupos;

use App\Enums\EscopoSerie;
use App\Models\Cota;
use App\Models\Grupo;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AgruparRequest extends FormRequest
{
    /** @var Collection<int, Cota>|null */
    private ?Collection $cotasSelecionadas = null;

    /**
     * An existing group must belong to the user (CA-093.5).
     */
    public function authorize(): bool
    {
        $grupo = $this->grupo();

        return $grupo === null || ($this->user()?->can('update', $grupo) ?? false);
    }

    /**
     * Shares of the user, and either a new group name (RN-150) or an existing group (RN-125).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cotas' => ['required', 'array', 'min:1'],
            'cotas.*' => ['integer', 'distinct', Rule::exists(Cota::class, 'id')->where('user_id', $this->user()?->id)],
            'grupo_id' => ['nullable', 'integer', Rule::exists(Grupo::class, 'id')],
            'nome' => ['required_without:grupo_id', 'nullable', 'string', 'max:100'],
            'escopo' => ['nullable', Rule::enum(EscopoSerie::class)],
        ];
    }

    /**
     * Shares of one type and one wallet, matching the existing group (RN-121, CA-090.2, CA-090.3).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $cotas = $this->cotas();
            $tipos = $cotas->map(fn (Cota $cota) => $cota->lancamento->tipo)->unique();
            $grupo = $this->grupo();

            if ($tipos->count() > 1) {
                $validator->errors()->add('cotas', 'Selecione itens do mesmo tipo: receitas e despesas não ficam no mesmo grupo.');
            } elseif ($cotas->pluck('carteira_id')->unique()->count() > 1) {
                $validator->errors()->add('cotas', 'Selecione itens da mesma carteira.');
            } elseif ($grupo !== null && ($grupo->tipo !== $tipos->first() || $grupo->carteira_id !== $cotas->first()?->carteira_id)) {
                $validator->errors()->add('grupo_id', 'Este grupo não aceita os itens selecionados.');
            }
        }];
    }

    /**
     * @return Collection<int, Cota>
     */
    public function cotas(): Collection
    {
        return $this->cotasSelecionadas ??= Cota::query()
            ->whereKey($this->validated('cotas'))
            ->with('lancamento.parcelamento')
            ->get();
    }

    public function grupo(): ?Grupo
    {
        return once(function (): ?Grupo {
            $id = $this->input('grupo_id');

            return is_numeric($id) ? Grupo::query()->find((int) $id) : null;
        });
    }

    public function escopo(): EscopoSerie
    {
        return $this->enum('escopo', EscopoSerie::class) ?? EscopoSerie::Esta;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cotas.required' => 'Selecione ao menos um item.',
            'cotas.*.exists' => 'Selecione itens seus.',
            'nome.required_without' => 'Informe o nome do grupo ou escolha um grupo existente.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['grupo_id' => 'grupo', 'nome' => 'nome do grupo'];
    }
}
