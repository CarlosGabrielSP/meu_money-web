<?php

namespace App\Http\Requests\Categorias;

use App\Enums\IconeCategoria;
use App\Enums\TipoCategoria;
use App\Enums\TipoLancamento;
use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Parcelamento;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalvarCategoriaRequest extends FormRequest
{
    /**
     * Creating is open to any user; updating only to the owner, never system categories (RN-031, RN-032).
     */
    public function authorize(): bool
    {
        $categoria = $this->categoria();

        return $categoria === null || ($this->user()?->can('update', $categoria) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100', $this->nomeDisponivel(...)],
            'tipo' => ['required', Rule::enum(TipoCategoria::class), $this->tipoCompativelComUso(...)],
            'icone' => ['nullable', Rule::enum(IconeCategoria::class)],
        ];
    }

    /**
     * Validated data. A missing icon gets the default on create and keeps the current one on update (RN-176).
     *
     * @return array{nome: string, tipo: string, icone: IconeCategoria|string}
     */
    public function dados(): array
    {
        return [
            'nome' => $this->validated('nome'),
            'tipo' => $this->validated('tipo'),
            'icone' => $this->validated('icone') ?? $this->categoria()->icone ?? IconeCategoria::Tag,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['icone' => 'ícone'];
    }

    /**
     * Name must not repeat a system category or another category of the user, ignoring case (RN-038).
     *
     * The comparison runs in PHP because SQLite lower() only folds ASCII letters.
     */
    private function nomeDisponivel(string $attribute, mixed $value, Closure $fail): void
    {
        $nome = mb_strtolower((string) $value);

        $emUso = Categoria::query()
            ->visiveisPara($this->user())
            ->when($this->categoria(), fn ($query, Categoria $categoria) => $query->whereKeyNot($categoria->id))
            ->pluck('nome')
            ->contains(fn (string $existente) => mb_strtolower($existente) === $nome);

        if ($emUso) {
            $fail('Já existe uma categoria com este nome.');
        }
    }

    /**
     * A type change must keep the category valid for every entry and series using it (RN-039).
     */
    private function tipoCompativelComUso(string $attribute, mixed $value, Closure $fail): void
    {
        $categoria = $this->categoria();
        $novoTipo = TipoCategoria::tryFrom((string) $value);

        if ($categoria === null || $novoTipo === null || $novoTipo === TipoCategoria::Ambos) {
            return;
        }

        $tipoIncompativel = $novoTipo === TipoCategoria::Receita ? TipoLancamento::Despesa : TipoLancamento::Receita;

        $usadaEmTipoIncompativel = Cota::query()
            ->where('categoria_id', $categoria->id)
            ->whereRelation('lancamento', 'tipo', $tipoIncompativel)
            ->exists()
            || Parcelamento::query()
                ->where('categoria_id', $categoria->id)
                ->where('tipo', $tipoIncompativel)
                ->exists();

        if ($usadaEmTipoIncompativel) {
            $fail('A categoria é usada em lançamentos de outro tipo. Use "ambos" ou mantenha o tipo atual.');
        }
    }

    private function categoria(): ?Categoria
    {
        $categoria = $this->route('categoria');

        return $categoria instanceof Categoria ? $categoria : null;
    }
}
