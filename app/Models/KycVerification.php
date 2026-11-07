<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KycVerification extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'reference',
        'check_type',
        'id_number_masked',
        'status',
        'field_matches',
        'record',
        'face_match',
        'raw_meta',
        'failure_reason',
        'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'field_matches' => 'array',
            'record' => 'array',
            'face_match' => 'array',
            'raw_meta' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
