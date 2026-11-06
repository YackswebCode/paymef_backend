<?php

namespace App\Console\Commands;

use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentService;
use Illuminate\Console\Command;
use Throwable;

class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--minutes=15 : Only attempts older than this are re-checked}';

    protected $description = 'Re-verify payment attempts that are still pending so lost webhooks cannot strand money';

    public function handle(PaymentService $payments): int
    {
        $cutoff = now()->subMinutes(max(1, (int) $this->option('minutes')));

        $attempts = PaymentAttempt::query()
            ->whereIn('status', ['initialized', 'pending'])
            ->whereNotNull('provider_reference')
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $settled = 0;
        $failed = 0;

        foreach ($attempts as $attempt) {
            try {
                $result = $payments->confirm((string) $attempt->provider_reference);

                if ($result?->status === 'success') {
                    $settled++;
                }
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->warn("Attempt {$attempt->id} could not be reconciled: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$attempts->count()} attempt(s): {$settled} settled, {$failed} errored.");

        return self::SUCCESS;
    }
}
