<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'school_id', 'invoice_id', 'payment_attempt_id', 'requested_by', 'provider',
    'provider_refund_id', 'amount_minor', 'currency', 'status', 'reason', 'provider_payload',
])]
class Refund extends Model
{
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'provider_payload' => 'array',
        ];
    }

    public function isProcessed(): bool
    {
        return $this->status === 'processed';
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function paymentAttempt(): BelongsTo
    {
        return $this->belongsTo(PaymentAttempt::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
