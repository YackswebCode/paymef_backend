<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'pledger_id', 'pledger_name', 'amount_minor', 'message', 'status', 'due_date',
])]
class CampaignPledge extends Model
{
    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'due_date' => 'date'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
