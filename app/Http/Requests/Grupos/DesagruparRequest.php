<?php

namespace App\Http\Requests\Grupos;

use App\Enums\EscopoSerie;
use App\Models\Cota;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DesagruparRequest extends FormRequest
{
    /** @var Collection<int, Cota>|null */
    private ?Collection $cotasSelecionadas = null;

    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Shares of the user (RN-125, RN-127).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cotas' => ['required', 'array', 'min:1'],
            'cotas.*' => ['integer', 'distinct', Rule::exists(Cota::class, 'id')->where($this->doUsuarioOuDelegadas(...))],
            'escopo' => ['nullable', Rule::enum(EscopoSerie::class)],
        ];
    }

    /**
     * Shares of one owner, so a member acts for one owner at a time (RN-292).
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isEmpty() && $this->cotas()->pluck('user_id')->unique()->count() > 1) {
                $validator->errors()->add('cotas', 'Selecione itens da mesma carteira.');
            }
        }];
    }

    /**
     * @return Collection<int, Cota>
     */
    public function cotas(): Collection
    {
        return $this->cotasSelecionadas ??= Cota::query()->whereKey($this->validated('cotas'))->with(['lancamento.parcelamento', 'user'])->get();
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
        ];
    }

    /**
     * Own shares, or shares of wallets delegated to the user (RN-292, CA-204.6).
     */
    private function doUsuarioOuDelegadas(QueryBuilder $query): void
    {
        /** @var User $user */
        $user = $this->user();

        $query->where(fn (QueryBuilder $cotas) => $cotas
            ->where('user_id', $user->id)
            ->orWhereIn('carteira_id', $user->carteirasDelegadas()->select('carteiras.id')));
    }

    /**
     * The owner of the selected shares, whom a member acts for (RN-292).
     */
    public function dono(): User
    {
        /** @var User $user */
        $user = $this->user();

        $primeira = $this->cotas()->first();

        return $primeira === null ? $user : $primeira->user;
    }
}
