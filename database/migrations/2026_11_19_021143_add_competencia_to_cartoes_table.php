<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rule that names the card invoices: closing month (default) or due month (RN-253, DEC-089).
     * Existing cards get `fechamento`.
     */
    public function up(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->string('competencia', 12)->default('fechamento')->after('dias_fechamento');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->dropColumn('competencia');
        });
    }
};
