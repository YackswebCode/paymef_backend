<?php

namespace App\Policies;

use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\User;

class InvoicePolicy
{
    /**
     * Administrators see everything, school admins are confined to their own
     * school, guardians may see invoices for their linked students, and
     * everyone else may only see invoices raised on themselves.
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

        if ($user->hasRole('parent')) {
            return Guardian::query()
                ->where('parent_id', $user->id)
                ->where('student_id', $invoice->student_id)
                ->exists();
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
