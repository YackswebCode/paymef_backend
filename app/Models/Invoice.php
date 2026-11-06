<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'school_id', 'student_id', 'reference', 'description', 'amount_minor', 'currency', 'status',
    'due_date', 'reminded_at',
])]
class Invoice extends Model
{
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'due_date' => 'date',
            'reminded_at' => 'datetime',
        ];
    }

    /**
     * Restrict a query to the invoices the given user may see.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        if ($user->hasRole('school_admin')) {
            return $query->where('school_id', $user->school_id);
        }

        // A guardian sees the invoices raised on the students they are linked to.
        if ($user->hasRole('parent')) {
            return $query->whereIn(
                'student_id',
                Guardian::query()->select('student_id')->where('parent_id', $user->id)
            );
        }

        return $query->where('student_id', $user->id);
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function paymentAttempts(): HasMany
    {
        return $this->hasMany(PaymentAttempt::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
