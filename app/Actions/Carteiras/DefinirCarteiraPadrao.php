<?php

namespace App\Actions\Carteiras;

use App\Models\Carteira;
use Illuminate\Support\Facades\DB;

class DefinirCarteiraPadrao
{
    /**
     * Make the wallet the owner's only default wallet, in one transaction (RN-003, CA-023.1).
     */
    public function handle(Carteira $carteira): void
    {
        DB::transaction(function () use ($carteira): void {
            Carteira::query()
                ->where('user_id', $carteira->user_id)
                ->whereKeyNot($carteira->getKey())
                ->update(['padrao' => false]);

            $carteira->forceFill(['padrao' => true])->save();
        });
    }
}
