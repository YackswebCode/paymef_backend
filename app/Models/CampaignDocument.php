<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campaign_id', 'type', 'title', 'file_url', 'file_size', 'status', 'verified_by', 'uploaded_at',
])]
class CampaignDocument extends Model
{
    protected function casts(): array
    {
        return ['uploaded_at' => 'date'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}
