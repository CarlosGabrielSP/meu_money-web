<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Wallet type; existing wallets are common ones (RN-190, DEC-080).
     */
    public function up(): void
    {
        Schema::table('carteiras', function (Blueprint $table) {
            $table->string('tipo', 10)->default('comum')->after('nome');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('carteiras', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
