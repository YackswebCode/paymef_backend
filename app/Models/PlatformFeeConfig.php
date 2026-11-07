<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'name', 'percentage_rate', 'fixed_fee_minor', 'min_fee_minor', 'currency', 'category',
    'effective_from', 'applies_to_new_only', 'notes', 'is_active',
])]
class PlatformFeeConfig extends Model
{
    protected function casts(): array
    {
        return [
            'percentage_rate' => 'float',
            'fixed_fee_minor' => 'integer',
            'min_fee_minor' => 'integer',
            'effective_from' => 'date',
            'applies_to_new_only' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function current(): self
    {
        return self::query()->where('is_active', true)->latest('effective_from')->firstOrFail();
    }
}
