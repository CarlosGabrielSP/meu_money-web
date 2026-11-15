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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('email');
            $table->timestamp('desativado_em')->nullable()->after('is_admin');
            $table->string('google_id')->nullable()->unique()->after('desativado_em');
            $table->foreignId('ultima_carteira_id')->nullable()->index()->after('google_id')->constrained('carteiras')->nullOnDelete();
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['ultima_carteira_id']);
            $table->dropIndex(['ultima_carteira_id']);
            $table->dropUnique(['google_id']);
            $table->dropColumn(['is_admin', 'desativado_em', 'google_id', 'ultima_carteira_id']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
