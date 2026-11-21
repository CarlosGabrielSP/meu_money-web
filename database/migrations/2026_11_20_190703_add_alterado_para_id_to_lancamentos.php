<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Owner on whose behalf a member made the last change, the author or a participant (RN-299).
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('alterado_para_id')->nullable()->after('alterado_em')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('alterado_para_id');
        });
    }
};
