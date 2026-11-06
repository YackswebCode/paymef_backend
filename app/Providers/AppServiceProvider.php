<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\School;
use App\Models\User;
use App\Policies\InvoicePolicy;
use App\Policies\SchoolPolicy;
use App\Policies\StudentPolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(School::class, SchoolPolicy::class);
        Gate::policy(User::class, StudentPolicy::class);

        ResetPassword::createUrlUsing(function (User $user, string $token) {
            return rtrim(config('paymef.frontend_url'), '/')
                .'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });
    }
}
