<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('cotas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lancamento_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('carteira_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('categoria_id')->index()->constrained()->restrictOnDelete();
            $table->bigInteger('valor');
            $table->decimal('percentual', 5, 2)->nullable();
            $table->boolean('executado')->default(false);
            $table->timestamp('executado_em')->nullable();
            $table->foreignId('grupo_id')->index()->nullable()->constrained()->nullOnDelete();
            $table->timestamp('vista_em')->nullable();
            $table->timestamps();

            $table->unique(['lancamento_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cotas');
    }
};
