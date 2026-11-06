<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * Restrict a query to the entries the given user may see.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->hasRole('school_admin') && $user->school_id !== null) {
            return $query->where('school_id', $user->school_id);
        }

        return $query->where('user_id', $user->id);
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
