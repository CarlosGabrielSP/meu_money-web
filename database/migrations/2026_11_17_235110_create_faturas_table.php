<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One invoice per card cycle, created when needed (RN-192, RN-193, RN-229).
     */
    public function up(): void
    {
        Schema::create('faturas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carteira_id')->constrained()->cascadeOnDelete();
            $table->date('referencia');
            $table->date('fechamento');
            $table->date('vencimento');
            $table->foreignId('carteira_vinculada_id')->nullable()->index()->constrained('carteiras')->nullOnDelete();
            $table->boolean('datas_editadas')->default(false);
            $table->timestamps();

            $table->unique(['carteira_id', 'referencia']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('faturas');
    }
};
