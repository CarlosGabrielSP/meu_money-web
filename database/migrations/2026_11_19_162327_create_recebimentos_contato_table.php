<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receipts of an "a receber" item: automatic, linked to the contact's mirror payment, or manual
     * without a payment (RN-217, RN-242, DEC-086).
     */
    public function up(): void
    {
        Schema::create('recebimentos_contato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('quitacao_contato_id')->index()->constrained('quitacoes_contato')->cascadeOnDelete();
            $table->bigInteger('valor');
            $table->date('data');
            $table->foreignId('pagamento_fatura_id')->nullable()->index()->constrained('pagamentos_fatura')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recebimentos_contato');
    }
};
