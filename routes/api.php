<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChildController;
use App\Http\Controllers\Api\EmailVerificationController;
use App\Http\Controllers\Api\GuardianController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\LedgerController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PaymentController;
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

        Route::patch('/profile', [ProfileController::class, 'update']);
        Route::post('/password', [ProfileController::class, 'password'])
            ->middleware('throttle:6,1');

        Route::post('/email/verification-notification', [EmailVerificationController::class, 'resend'])
            ->middleware('throttle:6,1');
    });
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
