<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('short_name')->nullable()->after('name');
            $table->string('type')->default('university')->after('short_name');
            $table->string('city')->nullable()->after('address');
            $table->string('state')->nullable()->after('city');
            $table->string('country', 2)->default('NG')->after('state');
            $table->string('website')->nullable()->after('country');
            $table->string('accreditation_number')->nullable()->after('website');
            $table->json('profile')->nullable()->after('accreditation_number');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('profile')->nullable()->after('phone');
            $table->json('settings')->nullable()->after('profile');
        });

        Schema::create('platform_fee_configs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('percentage_rate', 6, 4);
            $table->unsignedBigInteger('fixed_fee_minor')->default(0);
            $table->unsignedBigInteger('min_fee_minor')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->string('category')->default('all');
            $table->date('effective_from');
            $table->boolean('applies_to_new_only')->default(true);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->text('story');
            $table->string('category')->default('tuition');
            $table->string('student_name');
            $table->string('student_avatar')->nullable();
            $table->string('school_name')->nullable();
            $table->string('course_of_study')->nullable();
            $table->string('grade_level')->nullable();
            $table->unsignedBigInteger('net_base_goal_minor');
            $table->unsignedBigInteger('gross_target_minor');
            $table->unsignedBigInteger('gross_raised_minor')->default(0);
            $table->unsignedBigInteger('fee_collected_minor')->default(0);
            $table->unsignedBigInteger('disbursed_minor')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->string('status')->default('draft');
            $table->string('overfunding_policy')->default('continue_accepting');
            $table->boolean('beneficiary_verified')->default(false);
            $table->boolean('school_verified')->default(false);
            $table->boolean('featured')->default(false);
            $table->string('cover_image_url')->nullable();
            $table->unsignedInteger('donor_count')->default(0);
            $table->date('deadline_date')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'featured']);
            $table->index(['student_id', 'status']);
            $table->index(['school_id', 'status']);
        });

        Schema::create('campaign_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('title');
            $table->string('file_url')->default('#');
            $table->string('file_size')->nullable();
            $table->string('status')->default('pending');
            $table->string('verified_by')->nullable();
            $table->date('uploaded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_audit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->default('PayMef System');
            $table->string('action');
            $table->string('previous_status')->nullable();
            $table->string('new_status');
            $table->text('reason')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
        });

        Schema::create('campaign_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('donor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('donor_name')->default('Anonymous Donor');
            $table->string('donor_email')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedBigInteger('fee_minor')->default(0);
            $table->unsignedBigInteger('net_minor');
            $table->char('currency', 3)->default('NGN');
            $table->string('status')->default('pending');
            $table->string('reference')->unique();
            $table->text('message')->nullable();
            $table->boolean('anonymous')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['donor_id', 'created_at']);
        });

        Schema::create('campaign_pledges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pledger_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('pledger_name');
            $table->unsignedBigInteger('amount_minor');
            $table->text('message')->nullable();
            $table->string('status')->default('open');
            $table->date('due_date')->nullable();
            $table->timestamps();
        });

        Schema::create('fee_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('term');
            $table->string('academic_year');
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('NGN');
            $table->date('due_date')->nullable();
            $table->string('status')->default('published');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['school_id', 'status']);
        });

        Schema::create('savings_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('category')->default('tuition');
            $table->unsignedBigInteger('target_minor');
            $table->unsignedBigInteger('saved_minor')->default(0);
            $table->char('currency', 3)->default('NGN');
            $table->date('target_date')->nullable();
            $table->string('lock_type')->default('flexible');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('system');
            $table->string('title');
            $table->text('body');
            $table->string('action_tab')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
        });

        // Flexible, typed records for the long tail of product surfaces
        // (NGO programmes, admin cases, KYC bundles, messaging, referrals...)
        // that share the same read/write shape but not yet dedicated columns.
        Schema::create('workspace_records', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key')->nullable();
            $table->json('data');
            $table->string('status')->default('active');
            $table->timestamps();

            $table->unique(['type', 'key']);
            $table->index(['type', 'owner_id']);
            $table->index(['type', 'school_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_records');
        Schema::dropIfExists('app_notifications');
        Schema::dropIfExists('savings_goals');
        Schema::dropIfExists('fee_schedules');
        Schema::dropIfExists('campaign_pledges');
        Schema::dropIfExists('campaign_donations');
        Schema::dropIfExists('campaign_audit_events');
        Schema::dropIfExists('campaign_documents');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('platform_fee_configs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['profile', 'settings']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn([
                'short_name', 'type', 'city', 'state', 'country', 'website',
                'accreditation_number', 'profile',
            ]);
        });
    }
};
