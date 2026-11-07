<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id', 'title', 'category', 'target_minor', 'saved_minor', 'currency', 'target_date', 'lock_type', 'status',
])]
class SavingsGoal extends Model
{
    protected function casts(): array
    {
        return [
            'target_minor' => 'integer',
            'saved_minor' => 'integer',
            'target_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
