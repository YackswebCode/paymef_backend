<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'owner_id', 'school_id', 'key', 'data', 'status'])]
class WorkspaceRecord extends Model
{
    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function scopeOfType(Builder $query, string ...$types): Builder
    {
        return $query->whereIn('type', $types);
    }
}
