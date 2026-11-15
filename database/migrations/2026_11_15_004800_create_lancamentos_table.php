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
        Schema::create('lancamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->index()->constrained()->cascadeOnDelete();
            $table->string('descricao');
            $table->enum('tipo', ['receita', 'despesa']);
            $table->bigInteger('valor');
            $table->date('data')->index();
            $table->enum('modo_rateio', ['iguais', 'percentual', 'fixo'])->nullable();
            $table->foreignId('parcelamento_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('numero_parcela')->nullable();
            $table->text('observacao')->nullable();
            $table->string('anexo_caminho')->nullable();
            $table->string('anexo_nome')->nullable();
            $table->timestamps();

            $table->unique(['parcelamento_id', 'numero_parcela']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lancamentos');
    }
};
