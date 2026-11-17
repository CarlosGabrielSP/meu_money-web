<?php

namespace App\Http\Requests;

use App\Concerns\EscolheCarteira;
use App\Enums\PeriodoRelatorio;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string of the report (RN-177, RN-179). Invalid values fall back to defaults instead of failing,
 * except a wallet of another user (CA-140.4).
 */
class RelatorioRequest extends FormRequest
{
    use EscolheCarteira {
        carteiraInformada as carteiraDaUrl;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * The simple mode ignores the wallet of the URL: the report shows the last used or the default
     * wallet (RN-188, CA-149.2).
     */
    public function carteiraInformada(): ?int
    {
        /** @var User $user */
        $user = $this->user();

        return $user->modo_simples ? null : $this->carteiraDaUrl();
    }

    /**
     * Period of the URL, or the default when missing or invalid (CA-140.5).
     */
    public function periodo(): PeriodoRelatorio
    {
        $periodo = $this->query('periodo');

        return (is_string($periodo) ? PeriodoRelatorio::tryFrom($periodo) : null) ?? PeriodoRelatorio::PADRAO;
    }
}
