<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who acted when a member creates or changes an entry, or pays an invoice (RN-274, RN-275,
     * RN-281). Filled only for members; the stored name outlives the member's account.
     */
    public function up(): void
    {
        Schema::table('lancamentos', function (Blueprint $table) {
            $table->foreignId('criado_por_id')->nullable()->after('anexo_nome')->constrained('users')->nullOnDelete();
            $table->string('criado_por_nome')->nullable()->after('criado_por_id');
            $table->foreignId('alterado_por_id')->nullable()->after('criado_por_nome')->constrained('users')->nullOnDelete();
            $table->string('alterado_por_nome')->nullable()->after('alterado_por_id');
            $table->timestamp('alterado_em')->nullable()->after('alterado_por_nome');
        });

        Schema::table('pagamentos_fatura', function (Blueprint $table) {
            $table->foreignId('pago_por_id')->nullable()->after('data')->constrained('users')->nullOnDelete();
            $table->string('pago_por_nome')->nullable()->after('pago_por_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pagamentos_fatura', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pago_por_id');
            $table->dropColumn('pago_por_nome');
        });

        Schema::table('lancamentos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('criado_por_id');
            $table->dropConstrainedForeignId('alterado_por_id');
            $table->dropColumn(['criado_por_nome', 'alterado_por_nome', 'alterado_em']);
        });
    }
};
