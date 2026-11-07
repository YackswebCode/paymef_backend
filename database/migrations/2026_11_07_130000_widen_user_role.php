<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The frontend ships donor, NGO and partner-institution workspaces, so the
     * role list can no longer be a fixed enum. A short string keeps future
     * roles additive and works on both MySQL and the SQLite test database.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 32)->default('student')->change();
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement(
            "ALTER TABLE users MODIFY role ENUM('student', 'parent', 'school_admin', 'ngo_staff', 'admin') "
            ."NOT NULL DEFAULT 'student'"
        );
    }
};
