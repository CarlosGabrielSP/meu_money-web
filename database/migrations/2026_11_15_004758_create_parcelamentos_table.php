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
        Schema::create('parcelamentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carteira_id')->constrained()->restrictOnDelete();
            $table->foreignId('categoria_id')->constrained()->restrictOnDelete();
            $table->string('descricao');
            $table->enum('tipo', ['receita', 'despesa']);
            $table->bigInteger('valor_parcela');
            $table->unsignedSmallInteger('total_parcelas')->nullable();
            $table->enum('periodicidade', ['mensal', 'bimestral', 'trimestral', 'semestral', 'anual']);
            $table->date('data_inicio');
            $table->unsignedTinyInteger('dia_vencimento');
            $table->enum('modo_rateio', ['iguais', 'percentual', 'fixo'])->nullable();
            $table->foreignId('grupo_id')->nullable()->constrained()->nullOnDelete();
            $table->date('gerado_ate');
            $table->date('encerrado_em')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parcelamentos');
    }
};
