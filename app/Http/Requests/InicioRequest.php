<?php

namespace App\Http\Requests;

use App\Concerns\EscolheCarteira;
use App\Enums\TipoLancamento;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string of the main screen (RN-115). Invalid values fall back to defaults instead of failing,
 * except a wallet of another user (CA-080.4).
 */
class InicioRequest extends FormRequest
{
    use EscolheCarteira;

    /**
     * A member opens a delegated wallet on the home screen (RN-279, CA-203.1).
     */
    protected function habilidadeDaCarteira(): string
    {
        return 'operar';
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * First day of the month in the URL, or of the current month when missing or invalid (CA-080.5).
     */
    public function mes(): CarbonImmutable
    {
        if ($this->mesInformado()) {
            return CarbonImmutable::createFromFormat('!Y-m', (string) $this->query('mes')) ?: CarbonImmutable::today()->startOfMonth();
        }

        return CarbonImmutable::today()->startOfMonth();
    }

    /**
     * Whether the URL has a valid month; a card opens on the month of its open invoice without one
     * (RN-223, R3).
     */
    public function mesInformado(): bool
    {
        $mes = $this->query('mes');

        return is_string($mes) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mes) === 1;
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
