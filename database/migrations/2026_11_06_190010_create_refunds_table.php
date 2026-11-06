<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_attempt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider')->default('paystack');
            $table->string('provider_refund_id')->nullable()->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('NGN');
            $table->string('status')->default('pending');
            $table->string('reason')->nullable();
            $table->json('provider_payload')->nullable();
            $table->timestamps();

            $table->index(['payment_attempt_id', 'status']);
            $table->index(['invoice_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
