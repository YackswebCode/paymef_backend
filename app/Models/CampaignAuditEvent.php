<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'actor_id', 'actor_name', 'action', 'previous_status', 'new_status', 'reason', 'occurred_at',
])]
class CampaignAuditEvent extends Model
{
    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
