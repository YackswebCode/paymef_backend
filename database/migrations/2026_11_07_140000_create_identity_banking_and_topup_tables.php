<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_status', 32)->default('unverified')->after('role');
            $table->unsignedTinyInteger('kyc_tier')->default(0)->after('kyc_status');
            $table->timestamp('kyc_verified_at')->nullable()->after('kyc_tier');
        });

        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('bytid');
            $table->string('reference', 100)->unique();
            $table->string('check_type', 16);
            $table->string('id_number_masked', 32)->nullable();
            // pending | verified | not_found | failed | rejected
            $table->string('status', 32)->default('pending');
            $table->json('field_matches')->nullable();
            $table->json('record')->nullable();
            $table->json('face_match')->nullable();
            $table->json('raw_meta')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['check_type', 'status']);
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider', 64)->default('partner_bank');
            $table->string('provider_account_id')->nullable();
            $table->string('account_number', 20)->nullable();
            $table->string('account_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_code', 16)->nullable();
            $table->string('currency', 3)->default('NGN');
            // pending_partner_bank_approval | pending | active | rejected | suspended
            $table->string('status', 40)->default('pending_partner_bank_approval');
            $table->string('account_type', 32)->default('student_savings');
            $table->string('failure_reason')->nullable();
            $table->json('application')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'account_type', 'currency']);
        });

        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32)->default('paystack');
            $table->string('reference', 100)->unique();
            $table->string('provider_reference', 100)->nullable()->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');
            // pending | success | failed | abandoned
            $table->string('status', 32)->default('pending');
            $table->string('purpose', 32)->default('wallet_funding');
            $table->json('provider_payload')->nullable();
            $table->string('failure_reason')->nullable();
            $table->foreignId('ledger_entry_id')->nullable()->constrained('ledger_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_topups');
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('kyc_verifications');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['kyc_status', 'kyc_tier', 'kyc_verified_at']);
        });
    }
};
