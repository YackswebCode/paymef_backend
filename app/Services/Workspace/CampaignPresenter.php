<?php

namespace App\Services\Workspace;

use App\Models\Campaign;
use App\Models\CampaignAuditEvent;
use App\Models\CampaignDocument;

class CampaignPresenter
{
    /**
     * Serialize a campaign for the React client. Money stays in minor units and
     * is converted once, in the client adapter.
     *
     * @return array<string, mixed>
     */
    public function present(Campaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'slug' => $campaign->slug,
            'title' => $campaign->title,
            'story' => $campaign->story,
            'category' => $campaign->category,
            'student_id' => $campaign->student_id,
            'student_name' => $campaign->student_name,
            'student_avatar' => $campaign->student_avatar,
            'school_id' => $campaign->school_id,
            'school_name' => $campaign->school_name,
            'course_of_study' => $campaign->course_of_study,
            'grade_level' => $campaign->grade_level,
            'net_base_goal_minor' => $campaign->net_base_goal_minor,
            'gross_target_minor' => $campaign->gross_target_minor,
            'gross_raised_minor' => $campaign->gross_raised_minor,
            'fee_collected_minor' => $campaign->fee_collected_minor,
            'net_available_minor' => $campaign->netAvailableMinor(),
            'disbursed_minor' => $campaign->disbursed_minor,
            'currency' => $campaign->currency,
            'status' => $campaign->status,
            'overfunding_policy' => $campaign->overfunding_policy,
            'beneficiary_verified' => $campaign->beneficiary_verified,
            'school_verified' => $campaign->school_verified,
            'featured' => $campaign->featured,
            'cover_image_url' => $campaign->cover_image_url,
            'donor_count' => $campaign->donor_count,
            'progress_percent' => $campaign->progressPercent(),
            'deadline_date' => $campaign->deadline_date?->toDateString(),
            'created_at' => $campaign->created_at?->toIso8601String(),
            'submitted_at' => $campaign->submitted_at?->toIso8601String(),
            'approved_at' => $campaign->approved_at?->toIso8601String(),
            'published_at' => $campaign->published_at?->toIso8601String(),
            'documents' => $campaign->documents->map(fn (CampaignDocument $document): array => [
                'id' => $document->id,
                'type' => $document->type,
                'title' => $document->title,
                'file_url' => $document->file_url,
                'file_size' => $document->file_size,
                'uploaded_at' => $document->uploaded_at?->toDateString(),
                'status' => $document->status,
                'verified_by' => $document->verified_by,
            ])->all(),
            'audit_trail' => $campaign->auditEvents->map(fn (CampaignAuditEvent $event): array => [
                'id' => $event->id,
                'campaign_id' => $event->campaign_id,
                'admin_id' => $event->actor_id,
                'admin_name' => $event->actor_name,
                'action' => $event->action,
                'previous_status' => $event->previous_status,
                'new_status' => $event->new_status,
                'timestamp' => $event->occurred_at->toIso8601String(),
                'reason' => $event->reason,
            ])->all(),
        ];
    }

    /**
     * @param  iterable<int, Campaign>  $campaigns
     * @return list<array<string, mixed>>
     */
    public function collection(iterable $campaigns): array
    {
        return collect($campaigns)->map(fn (Campaign $campaign) => $this->present($campaign))->all();
    }
}
