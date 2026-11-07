<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'school_id', 'name', 'term', 'academic_year', 'amount_minor', 'currency', 'due_date', 'status', 'created_by',
])]
class FeeSchedule extends Model
{
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'due_date' => 'date'];
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        return $query->where('school_id', $user->school_id);
    }
}
