<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'school_id', 'user_id', 'entry_type', 'amount_minor', 'currency',
    'source_type', 'source_id', 'idempotency_key', 'meta', 'occurred_at',
])]
class LedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
