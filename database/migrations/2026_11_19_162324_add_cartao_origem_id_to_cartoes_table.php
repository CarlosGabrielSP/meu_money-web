<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirror card of a contact: points to the author's card the shares come from (RN-203, P-046).
     * The application deletes or detaches mirrors before the origin card goes (RN-234 to RN-236, RN-248).
     */
    public function up(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->foreignId('cartao_origem_id')->nullable()->after('carteira_vinculada_id')->index()->constrained('carteiras');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cartoes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cartao_origem_id');
        });
    }
};
