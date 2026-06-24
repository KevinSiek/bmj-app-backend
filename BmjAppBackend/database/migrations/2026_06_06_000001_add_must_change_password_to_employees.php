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
        Schema::table('employees', function (Blueprint $table) {
            // True while the account still uses an admin-issued temporary password. While set,
            // the EnsurePasswordChanged middleware blocks every request except changePassword,
            // so a temp password is single-use in effect: it gets you in only to set a real one.
            $table->boolean('must_change_password')->default(false)->after('temp_pass_expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
