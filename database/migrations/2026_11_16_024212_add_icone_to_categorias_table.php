<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * System categories get the icon of the map by name; user categories keep the default (DEC-073).
     * The map is a literal copy of IconeCategoria::GLOBAIS at this point, so later edits to the enum
     * do not change what this migration does.
     */
    public function up(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->string('icone', 30)->default('tag')->after('tipo');
        });

        $icones = [
            'Salário' => 'banknote',
            'Freelance' => 'laptop',
            'Investimentos' => 'trending-up',
            'Reembolso' => 'hand-coins',
            'Moradia' => 'house',
            'Alimentação' => 'utensils',
            'Mercado' => 'shopping-cart',
            'Transporte' => 'car',
            'Saúde' => 'heart-pulse',
            'Educação' => 'graduation-cap',
            'Lazer' => 'gamepad-2',
            'Assinaturas' => 'repeat',
            'Contas' => 'receipt',
            'Vestuário' => 'shirt',
            'Impostos' => 'landmark',
            'Cartão de crédito' => 'credit-card',
        ];

        foreach ($icones as $nome => $icone) {
            DB::table('categorias')->whereNull('user_id')->where('nome', $nome)->update(['icone' => $icone]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('categorias', function (Blueprint $table) {
            $table->dropColumn('icone');
        });
    }
};
