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
        Schema::create('contatos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitante_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('convidado_id')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pendente', 'aceito', 'recusado'])->default('pendente');
            $table->timestamp('respondido_em')->nullable();
            $table->timestamps();

            $table->unique(['solicitante_id', 'convidado_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contatos');
    }
};
