<?php

namespace App\Actions\Categorias;

use App\Models\Categoria;
use App\Models\Cota;
use App\Models\Parcelamento;
use Illuminate\Support\Facades\DB;

class ExcluirCategoria
{
    /**
     * Delete a user category, moving shares and series that use it to the system "Outros" (RN-037).
     */
    public function handle(Categoria $categoria): void
    {
        $outros = Categoria::query()->globais()->where('nome', 'Outros')->firstOrFail();

        DB::transaction(function () use ($categoria, $outros): void {
            Cota::query()->where('categoria_id', $categoria->id)->update(['categoria_id' => $outros->id]);
            Parcelamento::query()->where('categoria_id', $categoria->id)->update(['categoria_id' => $outros->id]);

            $categoria->delete();
        });
    }
}
