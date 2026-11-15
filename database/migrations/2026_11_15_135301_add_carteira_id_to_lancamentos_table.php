<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wallet where the author created the entry, used to show an entry without an author share (RN-145, DEC-053).
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('carteira_id')->nullable()->index()->after('user_id')->constrained('carteiras')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropForeign(['carteira_id']);
            $table->dropIndex(['carteira_id']);
            $table->dropColumn('carteira_id');
        });
    }
};
