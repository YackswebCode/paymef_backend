<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'donor_id', 'donor_name', 'donor_email', 'amount_minor', 'fee_minor', 'net_minor',
    'currency', 'status', 'reference', 'message', 'anonymous', 'paid_at',
])]
class CampaignDonation extends Model
{
    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'fee_minor' => 'integer',
            'net_minor' => 'integer',
            'anonymous' => 'boolean',
            'paid_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'donor_id');
    }
}
