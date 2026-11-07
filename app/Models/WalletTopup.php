<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTopup extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'reference',
        'provider_reference',
        'amount_minor',
        'currency',
        'status',
        'purpose',
        'provider_payload',
        'failure_reason',
        'ledger_entry_id',
        'paid_at',
        'settled_at',
    ];

    protected function casts(): array
    {
        return [
            'provider_payload' => 'array',
            'paid_at' => 'datetime',
            'settled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(LedgerEntry::class);
    }
}
