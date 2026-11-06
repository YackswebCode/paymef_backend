<?php

namespace App\Console\Commands;

use App\Mail\InvoiceReminderMail;
use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendInvoiceReminders extends Command
{
    protected $signature = 'invoices:send-reminders {--days=3 : Remind invoices due within this many days}';

    protected $description = 'Email students and guardians about unpaid invoices that are due soon or already late';

    public function handle(): int
    {
        $cutoff = now()->addDays(max(0, (int) $this->option('days')))->toDateString();

        $invoices = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', $cutoff)
            ->where(function ($query) {
                $query->whereNull('reminded_at')->orWhere('reminded_at', '<', now()->subDays(3));
            })
            ->with(['student.guardians'])
            ->orderBy('id')
            ->limit(200)
            ->get();

        $sent = 0;

        foreach ($invoices as $invoice) {
            $emails = collect([$invoice->student?->email])
                ->merge($invoice->student?->guardians->pluck('email') ?? [])
                ->filter()
                ->unique();

            foreach ($emails as $email) {
                Mail::to($email)->queue(new InvoiceReminderMail($invoice));
                $sent++;
            }

            // Recorded even when the student has no address, so a reminder is
            // never attempted more than once per cooldown window.
            $invoice->forceFill(['reminded_at' => now()])->save();
        }

        $this->info("Queued {$sent} reminder email(s) for {$invoices->count()} invoice(s).");

        return self::SUCCESS;
    }
}
