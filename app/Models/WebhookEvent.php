<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'provider', 'provider_event_id', 'event', 'reference', 'status', 'attempts', 'payload',
])]
class WebhookEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
        ];
    }
}
