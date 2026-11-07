<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankAccount extends Model
{
    protected $fillable = [
        'user_id',
        'requested_by',
        'provider',
        'provider_account_id',
        'account_number',
        'account_name',
        'bank_name',
        'bank_code',
        'currency',
        'status',
        'account_type',
        'failure_reason',
        'application',
        'metadata',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'application' => 'array',
            'metadata' => 'array',
            'approved_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && $this->account_number !== null;
    }
}
