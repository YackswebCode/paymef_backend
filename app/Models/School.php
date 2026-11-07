<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'short_name', 'type', 'slug', 'email', 'phone', 'address', 'city', 'state', 'country',
    'website', 'accreditation_number', 'verification_status', 'profile',
])]
class School extends Model
{
    protected function casts(): array
    {
        return ['profile' => 'array'];
    }

    public function feeSchedules(): HasMany
    {
        return $this->hasMany(FeeSchedule::class);
    }

    public function campaigns(): HasMany
    {
        return $this->hasMany(Campaign::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }
}
