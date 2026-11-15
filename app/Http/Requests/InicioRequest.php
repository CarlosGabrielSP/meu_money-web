<?php

namespace App\Http\Requests;

use App\Enums\TipoLancamento;
use App\Models\Carteira;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Query string of the main screen (RN-115). Invalid values fall back to defaults instead of failing,
 * except a wallet of another user (CA-080.4).
 */
class InicioRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Wallet from the URL, else the last used one, else the default (RN-090, CA-080.1 to CA-080.4).
     * A wallet of another user is forbidden; a wallet that no longer exists falls back.
     */
    public function carteira(): Carteira
    {
        /** @var User $user */
        $user = $this->user();

        $informada = $this->carteiraInformada() === null
            ? null
            : Carteira::query()->whereKey($this->carteiraInformada())->first();

        if ($informada !== null) {
            Gate::authorize('view', $informada);

            return $informada;
        }

        return $user->carteiras()->whereKey($user->ultima_carteira_id)->first()
            ?? $user->carteiras()->where('padrao', true)->firstOrFail();
    }

    public function carteiraInformada(): ?int
    {
        $carteira = $this->query('carteira');

        return is_string($carteira) && ctype_digit($carteira) ? (int) $carteira : null;
    }

    /**
     * First day of the month in the URL, or of the current month when missing or invalid (CA-080.5).
     */
    public function mes(): CarbonImmutable
    {
        $mes = $this->query('mes');

        if (is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m', $mes) ?: CarbonImmutable::today()->startOfMonth();
        }

        return CarbonImmutable::today()->startOfMonth();
    }

    /**
     * List filters; unknown values are ignored (RN-094).
     *
     * @return array{tipo: string|null, categoria: int|null, status: string|null, compartilhado: string|null}
     */
    public function filtros(): array
    {
        $tipo = $this->query('tipo');
        $categoria = $this->query('categoria');
        $status = $this->query('status');
        $compartilhado = $this->query('compartilhado');

        return [
            'tipo' => is_string($tipo) && TipoLancamento::tryFrom($tipo) !== null ? $tipo : null,
            'categoria' => is_string($categoria) && ctype_digit($categoria) ? (int) $categoria : null,
            'status' => in_array($status, ['executado', 'pendente'], true) ? $status : null,
            'compartilhado' => in_array($compartilhado, ['sim', 'nao'], true) ? $compartilhado : null,
        ];
    }
}
