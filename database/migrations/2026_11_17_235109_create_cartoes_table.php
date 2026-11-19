<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Data of a credit card, one to one with a wallet of type `cartao` (RN-191, DEC-084).
     */
    public function up(): void
    {
        Schema::create('cartoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carteira_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('dia_vencimento');
            $table->unsignedTinyInteger('dias_fechamento')->default(10);
            $table->foreignId('carteira_vinculada_id')->nullable()->index()->constrained('carteiras')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cartoes');
    }
};
