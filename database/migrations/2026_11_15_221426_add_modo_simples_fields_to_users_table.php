<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Every user that already exists is marked as past the first access, so they never get the
     * simple mode question (RN-168, DEC-068).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->date('data_nascimento')->nullable()->after('paleta');
            $table->boolean('modo_simples')->default(false)->after('data_nascimento');
            $table->timestamp('primeiro_acesso_em')->nullable()->after('modo_simples');
        });

        DB::table('users')->update(['primeiro_acesso_em' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['data_nascimento', 'modo_simples', 'primeiro_acesso_em']);
        });
    }
};
