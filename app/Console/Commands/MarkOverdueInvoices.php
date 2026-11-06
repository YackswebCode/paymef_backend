<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;

class MarkOverdueInvoices extends Command
{
    protected $signature = 'invoices:mark-overdue';

    protected $description = 'Move unpaid invoices past their due date into the overdue state';

    public function handle(): int
    {
        $updated = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid'])
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', now()->toDateString())
            ->update(['status' => 'overdue']);

        $this->info("Marked {$updated} invoice(s) overdue.");

        return self::SUCCESS;
    }
}
