<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Settlement of a contact's part on the author's invoice, by the contact or by the author
     * (RN-202, RN-204, RN-207, RN-240, RN-241, DEC-086).
     */
    public function up(): void
    {
        Schema::create('quitacoes_contato', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fatura_id')->index()->constrained()->cascadeOnDelete();
            $table->foreignId('contato_id')->index()->constrained('users');
            $table->bigInteger('valor');
            $table->string('origem', 10);
            $table->foreignId('pagamento_fatura_id')->index()->constrained('pagamentos_fatura')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('quitacoes_contato');
    }
};
