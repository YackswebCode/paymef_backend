<?php

use App\Http\Controllers\Api\AppNotificationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\FeeController;
use App\Http\Controllers\Api\NgoController;
use App\Http\Controllers\Api\SavingsGoalController;
use App\Http\Controllers\Api\WorkspaceController;
use App\Http\Controllers\Api\BankAccountController;
use App\Http\Controllers\Api\BytidWebhookController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PaystackGatewayController;
use App\Http\Controllers\Api\PaystackWebhookController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\SchoolController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    return response()->json([
        'status' => 'ok',
        'service' => 'paymef-api',
        'timestamp' => now()->toIso8601String(),
    ]);
});

// Provider callbacks arrive unauthenticated; authenticity is proven by the
// HMAC signature in the request header.
Route::post('/webhooks/paystack', PaystackWebhookController::class)
    ->middleware('throttle:120,1');

// Bytid signs webhook bodies with HMAC-SHA256; nothing else proves authenticity.
Route::post('/webhooks/bytid', BytidWebhookController::class)
    ->middleware('throttle:120,1');

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:6,1');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:6,1');

    // Signed link opened from the verification email: no session or token needed.
    Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])
        ->name('verification.verify');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);

        Route::get('/profile', [ProfileController::class, 'show']);
        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::post('/profile/avatar', [ProfileController::class, 'avatar']);
        Route::get('/profile/preferences', [ProfileController::class, 'preferences']);
        Route::put('/profile/preferences', [ProfileController::class, 'updatePreferences']);
        Route::post('/profile/password', [ProfileController::class, 'password'])
            ->middleware('throttle:6,1');

        Route::post('/password', [ProfileController::class, 'password'])
            ->middleware('throttle:6,1');

        Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    // Canonical account centre used by every role.
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::patch('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/avatar', [ProfileController::class, 'avatar']);
    Route::get('/profile/preferences', [ProfileController::class, 'preferences']);
    Route::put('/profile/preferences', [ProfileController::class, 'updatePreferences']);
    Route::post('/profile/password', [ProfileController::class, 'password'])
        ->middleware('throttle:6,1');
});

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    // Invoices
    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store'])
        ->middleware('role:school_admin,admin');
    Route::post('/invoices/bulk', [InvoiceController::class, 'bulk'])
        ->middleware('role:school_admin,admin');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::patch('/invoices/{invoice}', [InvoiceController::class, 'update'])
        ->middleware('role:school_admin,admin');
    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])
        ->middleware('role:school_admin,admin');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
        ->middleware('role:school_admin,admin');

    // Payments
    Route::get('/payments', [PaymentController::class, 'index']);
    Route::post('/invoices/{invoice}/pay', [PaymentController::class, 'store']);
    Route::get('/payments/{reference}', [PaymentController::class, 'show']);
    Route::post('/payments/{reference}/refund', [PaymentController::class, 'refund'])
        ->middleware('role:school_admin,admin');

    // Money
    Route::get('/wallet', [WalletController::class, 'show']);
    Route::get('/schools/{school}/wallet', [WalletController::class, 'school']);
    Route::get('/ledger', [LedgerController::class, 'index']);

    // Families
    Route::get('/children', [ChildController::class, 'index']);
    Route::post('/children', [ChildController::class, 'store'])
        ->middleware('throttle:20,1');

    Route::get('/students/{student}/guardians', [GuardianController::class, 'index'])
        ->middleware('role:school_admin,admin');
    Route::post('/students/{student}/guardians', [GuardianController::class, 'store'])
        ->middleware('role:school_admin,admin');
    Route::delete('/students/{student}/guardians/{parent}', [GuardianController::class, 'destroy'])
        ->middleware('role:school_admin,admin');

    // Schools and their people
    Route::get('/schools', [SchoolController::class, 'index']);
    Route::post('/schools', [SchoolController::class, 'store'])
        ->middleware('role:admin');
    Route::get('/schools/{school}', [SchoolController::class, 'show']);
    Route::patch('/schools/{school}', [SchoolController::class, 'update'])
        ->middleware('role:admin');
    Route::post('/schools/{school}/admins', [SchoolController::class, 'storeAdmin'])
        ->middleware('role:admin');
    Route::get('/schools/{school}/summary', [SchoolController::class, 'summary']);

    Route::get('/students', [StudentController::class, 'index'])
        ->middleware('role:school_admin,admin');
    Route::post('/students', [StudentController::class, 'store'])
        ->middleware('role:school_admin,admin');
    Route::post('/students/bulk', [StudentController::class, 'bulk'])
        ->middleware('role:school_admin,admin');
});

Route::middleware(['auth:sanctum', 'verified'])->group(function () {
    // One authenticated payload every dashboard hydrates from.
    Route::get('/workspace', [WorkspaceController::class, 'show']);

    // KYC identity verification (Bytid; server-side only)
    Route::get('/kyc', [KycController::class, 'show']);
    Route::post('/kyc/verify', [KycController::class, 'verify'])
        ->middleware('throttle:6,1');

    // Partner-bank account requests. Accounts stay pending until a bank signs.
    Route::get('/bank-accounts', [BankAccountController::class, 'index']);
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])
        ->middleware('throttle:10,1');

    // Paystack gateway: wallet funding is credited only after server verification.
    Route::prefix('paystack')->group(function () {
        Route::get('/config', [PaystackGatewayController::class, 'config']);
        Route::post('/initialize', [PaystackGatewayController::class, 'initialize'])
            ->middleware('throttle:10,1');
        Route::get('/verify/{reference}', [PaystackGatewayController::class, 'verify'])
            ->middleware('throttle:60,1');
        Route::get('/transactions', [PaystackGatewayController::class, 'transactions']);
        Route::get('/reconciliation', [PaystackGatewayController::class, 'reconciliation'])
            ->middleware('role:admin');
    });

    // Education crowdfunding
    Route::get('/campaigns', [CampaignController::class, 'index']);
    Route::post('/campaigns', [CampaignController::class, 'store']);
    Route::get('/campaigns/{campaign}', [CampaignController::class, 'show']);
    Route::post('/campaigns/{campaign}/status', [CampaignController::class, 'updateStatus']);
    Route::post('/campaigns/{campaign}/pledge', [CampaignController::class, 'pledge'])
        ->middleware('throttle:30,1');

    Route::prefix('admin/campaigns/{campaign}')->middleware('role:admin')->group(function () {
        Route::post('/moderate', [CampaignController::class, 'moderate']);
        Route::post('/documents/{document}/verify', [CampaignController::class, 'verifyDocument']);
        Route::post('/risk-note', [CampaignController::class, 'riskNote']);
    });

    // Platform fees and school fee schedules
    Route::get('/admin/fee-config', [FeeController::class, 'showConfig']);
    Route::put('/admin/fee-config', [FeeController::class, 'updateConfig']);
    Route::get('/fee-schedules', [FeeController::class, 'indexSchedules']);
    Route::post('/fee-schedules', [FeeController::class, 'storeSchedule']);

    // Savings goals
    Route::get('/savings-goals', [SavingsGoalController::class, 'index']);
    Route::post('/savings-goals', [SavingsGoalController::class, 'store']);
    Route::post('/savings-goals/{goal}/fund', [SavingsGoalController::class, 'fund']);

    // In-app notification centre
    Route::get('/notifications', [AppNotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [AppNotificationController::class, 'unreadCount']);
    Route::get('/notifications/preferences', [AppNotificationController::class, 'preferences']);
    Route::put('/notifications/preferences', [AppNotificationController::class, 'updatePreferences']);
    Route::post('/notifications/mark-all-read', [AppNotificationController::class, 'markAllRead']);
    Route::post('/notifications/test-event', [AppNotificationController::class, 'testEvent']);
    Route::post('/notifications/{notification}/read', [AppNotificationController::class, 'markRead']);

    // NGO / foundation workspace
    Route::prefix('ngo')->middleware('role:ngo,admin')->group(function () {
        Route::get('/initiatives', [NgoController::class, 'initiatives']);
        Route::post('/initiatives', [NgoController::class, 'storeInitiative']);
        Route::patch('/initiatives/{initiative}/status', [NgoController::class, 'updateInitiativeStatus']);

        Route::get('/beneficiaries', [NgoController::class, 'beneficiaries']);
        Route::post('/beneficiaries', [NgoController::class, 'storeBeneficiary']);
        Route::patch('/beneficiaries/{beneficiary}/verify', [NgoController::class, 'verifyBeneficiary']);
        Route::post('/beneficiaries/{beneficiary}/disburse', [NgoController::class, 'disburse']);

        Route::get('/donations', [NgoController::class, 'donations']);
        Route::post('/donations', [NgoController::class, 'storeDonation']);

        Route::get('/team', [NgoController::class, 'team']);
        Route::post('/team', [NgoController::class, 'storeTeamMember']);
        Route::patch('/team/{member}/permissions', [NgoController::class, 'updateTeamPermissions']);
        Route::delete('/team/{member}', [NgoController::class, 'destroyTeamMember']);

        Route::get('/impact-metrics', [NgoController::class, 'impactMetrics']);
        Route::post('/campaigns/{campaign}/support', [NgoController::class, 'supportCampaign']);
    });
});
