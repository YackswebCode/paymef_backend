<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            // Client-supplied key so a double-clicked "Pay" cannot create two
            // live checkouts for the same intent.
            $table->string('idempotency_key')->nullable()->unique()->after('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
