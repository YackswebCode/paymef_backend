<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /**
     * Administrators see everything, school admins are confined to their own
     * school, and everyone else may only see invoices raised on themselves.
     */
    public function view(User $user, Invoice $invoice): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->hasRole('school_admin')) {
            return $user->school_id !== null
                && (int) $user->school_id === (int) $invoice->school_id;
        }

        return (int) $user->id === (int) $invoice->student_id;
    }

    /**
     * Who may start a payment for this invoice.
     */
    public function pay(User $user, Invoice $invoice): bool
    {
        return $this->view($user, $invoice);
    }
}
