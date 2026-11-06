<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider')->default('paystack');
            $table->string('provider_event_id')->nullable();
            $table->string('event');
            $table->string('reference')->nullable();
            $table->string('status')->default('received');
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['event', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
