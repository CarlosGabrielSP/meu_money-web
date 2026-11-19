<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Invoice payments; the source wallet is null for an independent card (RN-195 to RN-197).
     */
    public function up(): void
    {
        Schema::create('pagamentos_fatura', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatura_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('carteira_id')->nullable()->index()->constrained()->nullOnDelete();
            $table->bigInteger('valor');
            $table->date('data');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pagamentos_fatura');
    }
};
