<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignAuditEvent;
use App\Models\CampaignDonation;
use App\Models\CampaignDocument;
use App\Models\LedgerEntry;
use App\Models\PlatformFeeConfig;
use App\Models\User;
use App\Services\Workspace\CampaignPresenter;
use App\Services\Workspace\WorkspacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CampaignController extends Controller
{
    public function __construct(
        private readonly CampaignPresenter $presenter,
        private readonly WorkspacePresenter $workspace,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $campaigns = Campaign::query()
            ->visibleTo($user)
            ->with('documents', 'auditEvents')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('student_id'), fn ($query) => $query->where('student_id', $request->integer('student_id')))
            ->when($request->filled('school_id'), fn ($query) => $query->where('school_id', $request->integer('school_id')))
            ->when($request->boolean('featured'), fn ($query) => $query->where('featured', true))
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';
                $query->where(fn ($q) => $q->where('title', 'like', $term)->orWhere('story', 'like', $term));
            })
            ->orderByDesc('featured')
            ->latest('id')
            ->get();

        return response()->json([
            'campaigns' => $this->presenter->collection($campaigns),
            'fee_config' => $this->workspace->feeConfig(),
        ]);
    }

    public function show(Request $request, string $campaign): JsonResponse
    {
        $model = $this->resolve($campaign);

        abort_unless(
            Campaign::query()->visibleTo($request->user())->whereKey($model->id)->exists(),
            403,
            'This campaign is not published.'
        );

        $model->load('documents', 'auditEvents', 'donations');

        return response()->json([
            'campaign' => $this->presenter->present($model),
            'donations' => $model->donations->map(fn (CampaignDonation $donation): array => [
                'id' => $donation->id,
                'donor_name' => $donation->anonymous ? 'Anonymous Donor' : $donation->donor_name,
                'amount_minor' => $donation->amount_minor,
                'message' => $donation->message,
                'paid_at' => $donation->paid_at?->toIso8601String(),
            ])->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'story' => ['required', 'string', 'max:5000'],
            'category' => ['nullable', 'string', 'max:40'],
            'net_base_goal_minor' => ['required', 'integer', 'min:10000'],
            'overfunding_policy' => ['nullable', 'in:continue_accepting,auto_close,require_review'],
            'deadline_date' => ['nullable', 'date', 'after:today'],
            'course_of_study' => ['nullable', 'string', 'max:120'],
            'grade_level' => ['nullable', 'string', 'max:60'],
            'cover_image_url' => ['nullable', 'string', 'max:2048'],
            'student_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $actor = $request->user();
        $student = $this->campaignStudent($actor, $data['student_id'] ?? null);

        $config = PlatformFeeConfig::query()->where('is_active', true)->latest('effective_from')->first();
        $rate = $config?->percentage_rate ?? 0.10;
        $fixed = $config?->fixed_fee_minor ?? 0;

        // Preserve the student's net goal: gross = net / (1 - rate).
        $gross = (int) ceil($data['net_base_goal_minor'] / max(0.01, 1 - $rate)) + $fixed;

        $campaign = DB::transaction(function () use ($data, $student, $gross, $config) {
            $campaign = Campaign::create([
                'slug' => $this->uniqueSlug($data['title']),
                'student_id' => $student->id,
                'school_id' => $student->school_id,
                'title' => $data['title'],
                'story' => $data['story'],
                'category' => $data['category'] ?? 'tuition',
                'student_name' => $student->name,
                'student_avatar' => $student->profile['avatar_url'] ?? null,
                'school_name' => $student->school?->name,
                'course_of_study' => $data['course_of_study'] ?? ($student->profile['course_of_study'] ?? null),
                'grade_level' => $data['grade_level'] ?? ($student->profile['level'] ?? null),
                'net_base_goal_minor' => $data['net_base_goal_minor'],
                'gross_target_minor' => $gross,
                'currency' => $config?->currency ?? 'NGN',
                'status' => 'submitted',
                'overfunding_policy' => $data['overfunding_policy'] ?? 'continue_accepting',
                'cover_image_url' => $data['cover_image_url'] ?? null,
                'deadline_date' => $data['deadline_date'] ?? null,
                'submitted_at' => now(),
            ]);

            CampaignAuditEvent::create([
                'campaign_id' => $campaign->id,
                'actor_id' => $student->id,
                'actor_name' => $student->name,
                'action' => 'submit_campaign',
                'previous_status' => 'draft',
                'new_status' => 'submitted',
                'reason' => 'Campaign submitted for compliance review.',
                'occurred_at' => now(),
            ]);

            return $campaign;
        });

        return response()->json([
            'campaign' => $this->presenter->present($campaign->fresh(['documents', 'auditEvents'])),
        ], 201);
    }

    public function updateStatus(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $actor = $request->user();
        $target = $data['status'];

        $allowed = $actor->isAdmin()
            ? ['draft', 'submitted', 'in_review', 'approved', 'rejected', 'published', 'active', 'completed', 'cancelled']
            : ['submitted', 'active', 'completed', 'cancelled'];

        abort_unless(in_array($target, $allowed, true), 403, 'You cannot move a campaign to that status.');
        abort_unless(
            $actor->isAdmin() || (int) $campaign->student_id === (int) $actor->id,
            403,
            'Only the campaign owner or an administrator can change its status.'
        );

        $this->transition($campaign, $target, $actor, $data['reason'] ?? null);

        return response()->json([
            'campaign' => $this->presenter->present($campaign->fresh(['documents', 'auditEvents'])),
        ]);
    }

    public function moderate(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Administrator access required.');

        $data = $request->validate([
            'status' => ['required', 'in:in_review,approved,rejected,published,active,cancelled'],
            'reason' => ['nullable', 'string', 'max:1000'],
            'verify_beneficiary' => ['nullable', 'boolean'],
            'verify_school' => ['nullable', 'boolean'],
        ]);

        $campaign->fill(array_filter([
            'beneficiary_verified' => $data['verify_beneficiary'] ?? null,
            'school_verified' => $data['verify_school'] ?? null,
        ], fn ($value) => $value !== null))->save();

        $this->transition($campaign, $data['status'], $request->user(), $data['reason'] ?? null);

        return response()->json([
            'campaign' => $this->presenter->present($campaign->fresh(['documents', 'auditEvents'])),
        ]);
    }

    public function verifyDocument(Request $request, Campaign $campaign, CampaignDocument $document): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Administrator access required.');
        abort_unless((int) $document->campaign_id === (int) $campaign->id, 404);

        $data = $request->validate(['status' => ['required', 'in:verified,rejected']]);

        $document->update([
            'status' => $data['status'],
            'verified_by' => $request->user()->name,
        ]);

        return response()->json([
            'document' => [
                'id' => $document->id,
                'status' => $document->status,
                'verified_by' => $document->verified_by,
            ],
            'campaign' => $this->presenter->present($campaign->fresh(['documents', 'auditEvents'])),
        ]);
    }

    /**
     * Record a pledge/donation. Amounts arrive in major units from the donor UI
     * and are stored in minor units with the platform fee split out.
     */
    public function pledge(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required_without:amount_minor', 'numeric', 'min:1'],
            'amount_minor' => ['required_without:amount|integer', 'integer', 'min:100'],
            'donor_name' => ['nullable', 'string', 'max:120'],
            'donorName' => ['nullable', 'string', 'max:120'],
            'donor_email' => ['nullable', 'email'],
            'donorEmail' => ['nullable', 'email'],
            'is_anonymous' => ['nullable', 'boolean'],
            'isAnonymous' => ['nullable', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:successful,pending,failed,abandoned'],
        ]);

        $amountMinor = isset($data['amount_minor'])
            ? (int) $data['amount_minor']
            : (int) round(((float) $data['amount']) * 100);

        abort_unless(in_array($campaign->status, Campaign::PUBLIC_STATUSES, true), 409, 'This campaign is not accepting donations.');

        $config = PlatformFeeConfig::current();
        $feeMinor = max(
            $config->min_fee_minor,
            (int) round($amountMinor * $config->percentage_rate) + $config->fixed_fee_minor
        );
        $netMinor = max(0, $amountMinor - $feeMinor);

        $status = $data['status'] ?? 'successful';
        $anonymous = (bool) ($data['is_anonymous'] ?? $data['isAnonymous'] ?? false);

        $donation = DB::transaction(function () use ($request, $campaign, $amountMinor, $feeMinor, $netMinor, $status, $anonymous, $data) {
            $donation = CampaignDonation::create([
                'campaign_id' => $campaign->id,
                'donor_id' => $request->user()?->id,
                'donor_name' => $data['donor_name'] ?? $data['donorName'] ?? 'Anonymous Donor',
                'donor_email' => $data['donor_email'] ?? $data['donorEmail'] ?? null,
                'amount_minor' => $amountMinor,
                'fee_minor' => $feeMinor,
                'net_minor' => $netMinor,
                'currency' => $campaign->currency,
                'status' => $status === 'abandoned' ? 'failed' : $status,
                'reference' => 'PMF-CRW-'.strtoupper(bin2hex(random_bytes(5))),
                'message' => $data['message'] ?? null,
                'anonymous' => $anonymous,
                'paid_at' => $status === 'successful' ? now() : null,
            ]);

            if ($status !== 'successful') {
                return $donation;
            }

            $campaign->increment('gross_raised_minor', $amountMinor);
            $campaign->increment('fee_collected_minor', $feeMinor);
            $campaign->increment('donor_count');

            if ($campaign->student_id !== null) {
                LedgerEntry::create([
                    'school_id' => $campaign->school_id,
                    'user_id' => $campaign->student_id,
                    'entry_type' => 'credit',
                    'amount_minor' => $netMinor,
                    'currency' => $campaign->currency,
                    'source_type' => 'campaign_donation',
                    'source_id' => (string) $donation->id,
                    'idempotency_key' => 'donation_'.$donation->id,
                    'meta' => ['campaign_id' => $campaign->id, 'fee_minor' => $feeMinor],
                    'occurred_at' => now(),
                ]);
            }

            CampaignAuditEvent::create([
                'campaign_id' => $campaign->id,
                'actor_id' => $request->user()?->id,
                'actor_name' => $anonymous ? 'Anonymous Donor' : ($data['donor_name'] ?? $data['donorName'] ?? 'Donor'),
                'action' => 'donation_received',
                'previous_status' => $campaign->status,
                'new_status' => $campaign->status,
                'reason' => 'Donation of '.$amountMinor.' minor units received.',
                'occurred_at' => now(),
            ]);

            return $donation;
        });

        return response()->json([
            'donation' => [
                'id' => $donation->id,
                'reference' => $donation->reference,
                'amount_minor' => $donation->amount_minor,
                'fee_minor' => $donation->fee_minor,
                'net_minor' => $donation->net_minor,
                'status' => $donation->status,
                'receipt_reference' => $donation->reference,
            ],
            'receiptReference' => $donation->reference,
            'receipt' => [
                'platformFee' => (int) round($feeMinor / 100),
                'netCreditedToStudent' => (int) round($netMinor / 100),
            ],
            'campaign' => $this->presenter->present($campaign->fresh(['documents', 'auditEvents'])),
        ], 201);
    }

    public function riskNote(Request $request, Campaign $campaign): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Administrator access required.');

        $data = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
            'newReviewState' => ['nullable', 'string', 'max:40'],
        ]);

        $event = CampaignAuditEvent::create([
            'campaign_id' => $campaign->id,
            'actor_id' => $request->user()->id,
            'actor_name' => $request->user()->name,
            'action' => 'risk_note',
            'previous_status' => $campaign->status,
            'new_status' => $campaign->status,
            'reason' => $data['note'],
            'occurred_at' => now(),
        ]);

        if (! empty($data['newReviewState'])) {
            $campaign->update(['status' => $data['newReviewState']]);
        }

        return response()->json([
            'note' => ['id' => $event->id, 'reason' => $event->reason, 'actor' => $event->actor_name],
            'campaign' => $this->presenter->present($campaign->fresh(['documents', 'auditEvents'])),
        ], 201);
    }

    private function transition(Campaign $campaign, string $target, User $actor, ?string $reason): void
    {
        $previous = $campaign->status;

        $stamps = match ($target) {
            'submitted' => ['submitted_at' => now()],
            'approved' => ['approved_at' => now()],
            'published' => ['published_at' => now()],
            'completed' => ['completed_at' => now()],
            default => [],
        };

        $campaign->update(array_merge(['status' => $target], $stamps));

        CampaignAuditEvent::create([
            'campaign_id' => $campaign->id,
            'actor_id' => $actor->id,
            'actor_name' => $actor->name,
            'action' => 'update_status',
            'previous_status' => $previous,
            'new_status' => $target,
            'reason' => $reason ?? 'Status updated from the PayMef console.',
            'occurred_at' => now(),
        ]);
    }

    private function campaignStudent(User $actor, ?int $studentId): User
    {
        if ($studentId === null || (int) $studentId === (int) $actor->id) {
            abort_unless($actor->hasRole('student'), 403, 'Only students can raise education campaigns.');

            return $actor->loadMissing('school');
        }

        $student = User::query()->with('school')->findOrFail($studentId);

        $actorIsParent = $actor->hasRole('parent') && $actor->children()->whereKey($student->id)->exists();

        abort_unless($actor->isAdmin() || $actorIsParent, 403, 'You cannot raise a campaign for this student.');
        abort_unless($student->hasRole('student'), 422, 'The beneficiary must be a student account.');

        return $student;
    }

    private function resolve(string $campaign): Campaign
    {
        return Campaign::query()
            ->where('slug', $campaign)
            ->when(ctype_digit($campaign), fn ($query) => $query->orWhere('id', (int) $campaign))
            ->firstOrFail();
    }

    private function uniqueSlug(string $title): string
    {
        $base = str()->slug($title);
        $slug = $base;
        $suffix = 2;

        while (Campaign::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
