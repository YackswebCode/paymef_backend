<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('description')->nullable()->after('reference');
            $table->timestamp('reminded_at')->nullable()->after('due_date');

            // school_id+status already exists on the base table; this one serves
            // the overdue and reminder scans.
            $table->index(['status', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['status', 'due_date']);
            $table->dropColumn(['description', 'reminded_at']);
        });
    }
};
