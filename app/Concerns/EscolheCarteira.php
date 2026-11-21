<?php

namespace App\Concerns;

use App\Models\Carteira;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * Wallet of a screen that shows one wallet at a time, from the "carteira" query string (RN-090,
 * RN-115, RN-177).
 */
trait EscolheCarteira
{
    /**
     * Wallet from the URL, else the last used one, else the default (RN-090, CA-080.1 to CA-080.4,
     * CA-140.1 to CA-140.4). A wallet of another user is forbidden, except a wallet delegated to the
     * user on the home screen (RN-279, CA-203.1); a wallet that no longer exists falls back.
     */
    public function carteira(): Carteira
    {
        /** @var User $user */
        $user = $this->user();

        $informada = $this->carteiraInformada() === null
            ? null
            : Carteira::query()->whereKey($this->carteiraInformada())->first();

        if ($informada !== null) {
            Gate::authorize($this->habilidadeDaCarteira(), $informada);

            return $informada;
        }

        return $user->carteiras()->whereKey($user->ultima_carteira_id)->first()
            ?? $user->carteiras()->where('padrao', true)->firstOrFail();
    }

    /**
     * Ability the wallet from the URL needs; only the home screen accepts a delegated wallet (RN-279).
     */
    protected function habilidadeDaCarteira(): string
    {
        return 'view';
    }

    public function carteiraInformada(): ?int
    {
        $carteira = $this->query('carteira');

        return is_string($carteira) && ctype_digit($carteira) ? (int) $carteira : null;
    }
}
