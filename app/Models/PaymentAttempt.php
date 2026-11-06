<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'invoice_id', 'payer_id', 'provider', 'provider_reference', 'idempotency_key',
    'amount_minor', 'currency', 'status', 'provider_payload',
])]
class PaymentAttempt extends Model
{
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'provider_payload' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payer_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
