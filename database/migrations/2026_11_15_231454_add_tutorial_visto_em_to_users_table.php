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
     * Every user that already exists is marked as having seen the tutorial, even the ones that never
     * signed in (RN-168, DEC-069).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('tutorial_visto_em')->nullable()->after('primeiro_acesso_em');
        });

        DB::table('users')->update(['tutorial_visto_em' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('tutorial_visto_em');
        });
    }
};
