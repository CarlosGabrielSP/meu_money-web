<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoice of a share in a card; null outside cards (RN-192).
     */
    public function up(): void
    {
        Schema::table('cotas', function (Blueprint $table) {
            $table->foreignId('fatura_id')->nullable()->index()->after('carteira_id')->constrained('faturas')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cotas', function (Blueprint $table) {
            $table->dropForeign(['fatura_id']);
            $table->dropIndex(['fatura_id']);
            $table->dropColumn('fatura_id');
        });
    }
};
