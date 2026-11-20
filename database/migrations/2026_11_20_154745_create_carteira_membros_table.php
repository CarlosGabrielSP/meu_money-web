<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Delegation of a common wallet to a contact, the member (RN-269, RN-270, DEC-097).
     */
    public function up(): void
    {
        Schema::create('carteira_membros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('carteira_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 10);
            $table->timestamp('respondido_em')->nullable();
            $table->timestamps();

            $table->unique(['carteira_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('carteira_membros');
    }
};
