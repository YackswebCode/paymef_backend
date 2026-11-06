<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Re-verify money that is still pending so a lost webhook cannot strand it.
Schedule::command('payments:reconcile')->everyFifteenMinutes()->withoutOverlapping();

Schedule::command('invoices:mark-overdue')->hourly()->withoutOverlapping();

Schedule::command('invoices:send-reminders')->dailyAt('08:00')->withoutOverlapping();

Schedule::command('sanctum:prune-expired --hours=24')->daily();
