<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', [
                'student', 'parent', 'school_admin', 'ngo_staff', 'admin',
            ])->default('student')->after('password');

            $table->foreignId('school_id')
                ->nullable()
                ->after('role')
                ->constrained('schools')
                ->nullOnDelete();

            $table->string('phone')->nullable()->after('school_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
            $table->dropColumn(['role', 'phone']);
        });
    }
};
