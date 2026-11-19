<?php

namespace App\Actions\Carteiras;

use App\Models\Carteira;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DefinirCarteiraPadrao
{
    /**
     * Make the wallet the owner's only default wallet, in one transaction (RN-003, CA-023.1). A card
     * is never the default (RN-190, CA-160.6).
     *
     * @throws ValidationException
     */
    public function handle(Carteira $carteira): void
    {
        if ($carteira->isCartao()) {
            throw ValidationException::withMessages(['carteira' => 'Cartão de crédito não pode ser a carteira padrão.']);
        }

        DB::transaction(function () use ($carteira): void {
            Carteira::query()
                ->where('user_id', $carteira->user_id)
                ->whereKeyNot($carteira->getKey())
                ->update(['padrao' => false]);

            $carteira->forceFill(['padrao' => true])->save();
        });
    }
}
