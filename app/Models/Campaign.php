<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'slug', 'student_id', 'school_id', 'title', 'story', 'category', 'student_name', 'student_avatar',
    'school_name', 'course_of_study', 'grade_level', 'net_base_goal_minor', 'gross_target_minor',
    'gross_raised_minor', 'fee_collected_minor', 'disbursed_minor', 'currency', 'status',
    'overfunding_policy', 'beneficiary_verified', 'school_verified', 'featured', 'cover_image_url',
    'donor_count', 'deadline_date', 'submitted_at', 'approved_at', 'published_at', 'completed_at',
])]
class Campaign extends Model
{
    protected function casts(): array
    {
        return [
            'net_base_goal_minor' => 'integer',
            'gross_target_minor' => 'integer',
            'gross_raised_minor' => 'integer',
            'fee_collected_minor' => 'integer',
            'disbursed_minor' => 'integer',
            'beneficiary_verified' => 'boolean',
            'school_verified' => 'boolean',
            'featured' => 'boolean',
            'donor_count' => 'integer',
            'deadline_date' => 'date',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** Statuses an anonymous visitor may see on the public campaign pages. */
    public const PUBLIC_STATUSES = ['published', 'active', 'completed'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CampaignDocument::class);
    }

    public function auditEvents(): HasMany
    {
        return $this->hasMany(CampaignAuditEvent::class)->latest('occurred_at');
    }

    public function donations(): HasMany
    {
        return $this->hasMany(CampaignDonation::class)->latest();
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(CampaignPledge::class)->latest();
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->isAdmin()) {
            return $query;
        }

        $statuses = self::PUBLIC_STATUSES;

        if ($user->hasRole('school_admin')) {
            return $query->where(
                fn (Builder $q) => $q->whereIn('status', $statuses)
                    ->orWhere('school_id', $user->school_id)
            );
        }

        return $query->where(
            fn (Builder $q) => $q->whereIn('status', $statuses)
                ->orWhere('student_id', $user->id)
        );
    }

    public function netAvailableMinor(): int
    {
        return max(0, $this->gross_raised_minor - $this->fee_collected_minor - $this->disbursed_minor);
    }

    public function progressPercent(): float
    {
        if ($this->gross_target_minor <= 0) {
            return 0.0;
        }

        return round(min(100, ($this->gross_raised_minor / $this->gross_target_minor) * 100), 2);
    }
}
