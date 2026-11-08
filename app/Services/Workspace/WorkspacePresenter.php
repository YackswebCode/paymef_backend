<?php

namespace App\Services\Workspace;

use App\Models\AppNotification;
use App\Models\Campaign;
use App\Models\FeeSchedule;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\PlatformFeeConfig;
use App\Models\SavingsGoal;
use App\Models\School;
use App\Models\User;
use App\Models\WorkspaceRecord;
use App\Services\Banking\PartnerBankService;
use App\Services\Kyc\KycService;
use App\Services\Payments\PaymentService;
use App\Services\Wallet\WalletService;

/**
 * Builds the single payload every dashboard hydrates from.
 *
 * It deliberately aggregates several domain tables instead of making the React
 * client fan out to a dozen endpoints on boot. Sections are role-scoped: a
 * student never receives school-wide or platform-wide figures.
 */
class WorkspacePresenter
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly PaymentService $payments,
        private readonly CampaignPresenter $campaigns,
        private readonly KycService $kyc,
        private readonly PartnerBankService $banks,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(User $user): array
    {
        $user->loadMissing('school');

        $payload = [
            'user' => $this->user($user),
            'school' => $this->school($user->school),
            'wallet' => $this->wallets->forUser($user),
            'invoices' => $this->invoices($user),
            'transactions' => $this->transactions($user),
            'campaigns' => $this->campaigns($user),
            'fee_config' => $this->feeConfig(),
            'fee_schedules' => $this->feeSchedules($user),
            'savings_goals' => $this->savingsGoals($user),
            'notifications' => $this->notifications($user),
            'kyc' => $this->kyc->summary($user),
            'bank_account' => $this->banks->present($this->banks->forUser($user)),
            'capabilities' => [
                // The bank rails stay dark until the partner banks sign; the UI
                // reads this flag instead of inventing account numbers.
                ...$this->banks->capabilities(),
                'card_checkout' => config('paymef.paystack.secret_key') && config('paymef.paystack.public_key')
                    ? 'live'
                    : 'unconfigured',
            ],
            'generated_at' => now()->toIso8601String(),
        ];

        if ($user->hasRole('parent')) {
            $payload['children'] = $this->children($user);
        }

        if ($user->hasRole('school_admin') && $user->school !== null) {
            $payload['school_wallet'] = $this->wallets->forSchool($user->school);
            $payload['school_admin'] = $this->schoolAdmin($user->school);
        }

        if ($user->isAdmin()) {
            $payload['admin'] = $this->admin();
        }

        if ($user->hasRole('ngo')) {
            $payload['ngo'] = $this->ngo($user);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $user->phone,
            'role' => $user->role,
            'school_id' => $user->school_id,
            'email_verified' => $user->email_verified_at !== null,
            'created_at' => $user->created_at?->toIso8601String(),
            'profile' => $user->profile,
            'settings' => $user->settings,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function school(?School $school): ?array
    {
        if ($school === null) {
            return null;
        }

        return [
            'id' => $school->id,
            'name' => $school->name,
            'short_name' => $school->short_name,
            'slug' => $school->slug,
            'type' => $school->type,
            'email' => $school->email,
            'phone' => $school->phone,
            'address' => $school->address,
            'city' => $school->city,
            'state' => $school->state,
            'country' => $school->country,
            'website' => $school->website,
            'accreditation_number' => $school->accreditation_number,
            'verification_status' => $school->verification_status,
            'profile' => $school->profile,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invoices(User $user): array
    {
        return Invoice::query()
            ->visibleTo($user)
            ->with('school:id,name,short_name,slug', 'student:id,name,email,phone,profile')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (Invoice $invoice): array => array_merge(
                $invoice->toArray(),
                ['outstanding_minor' => $this->payments->outstandingFor($invoice)]
            ))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function transactions(User $user): array
    {
        return LedgerEntry::query()
            ->visibleTo($user)
            ->latest('occurred_at')
            ->latest('id')
            ->limit(60)
            ->get()
            ->map(fn (LedgerEntry $entry): array => [
                'id' => $entry->id,
                'entry_type' => $entry->entry_type,
                'amount_minor' => (int) $entry->amount_minor,
                'currency' => $entry->currency,
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'user_id' => $entry->user_id,
                'school_id' => $entry->school_id,
                'meta' => $entry->meta,
                'occurred_at' => $entry->occurred_at->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function campaigns(User $user): array
    {
        return $this->campaigns->collection(
            Campaign::query()
                ->visibleTo($user)
                ->with('documents', 'auditEvents')
                ->orderByDesc('featured')
                ->latest('id')
                ->limit(60)
                ->get()
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function feeConfig(): ?array
    {
        $config = PlatformFeeConfig::query()->where('is_active', true)->latest('effective_from')->first();

        if ($config === null) {
            return null;
        }

        return [
            'id' => $config->id,
            'name' => $config->name,
            'percentage_rate' => $config->percentage_rate,
            'fixed_fee_minor' => $config->fixed_fee_minor,
            'min_fee_minor' => $config->min_fee_minor,
            'currency' => $config->currency,
            'category' => $config->category,
            'effective_from' => $config->effective_from->toDateString(),
            'applies_to_new_only' => $config->applies_to_new_only,
            'notes' => $config->notes,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function feeSchedules(User $user): array
    {
        $query = FeeSchedule::query()->with('school:id,name,short_name');

        if (! $user->isAdmin()) {
            $query->where('school_id', $user->school_id);
        }

        return $query->orderBy('due_date')->get()->map(fn (FeeSchedule $schedule): array => [
            'id' => $schedule->id,
            'school_id' => $schedule->school_id,
            'school_name' => $schedule->school?->name,
            'name' => $schedule->name,
            'term' => $schedule->term,
            'academic_year' => $schedule->academic_year,
            'amount_minor' => $schedule->amount_minor,
            'currency' => $schedule->currency,
            'due_date' => $schedule->due_date?->toDateString(),
            'status' => $schedule->status,
        ])->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function savingsGoals(User $user): array
    {
        return SavingsGoal::query()
            ->where('user_id', $user->id)
            ->latest()
            ->get()
            ->map(fn (SavingsGoal $goal): array => $this->savingsGoal($goal))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function savingsGoal(SavingsGoal $goal): array
    {
        return [
            'id' => $goal->id,
            'user_id' => $goal->user_id,
            'title' => $goal->title,
            'category' => $goal->category,
            'target_minor' => $goal->target_minor,
            'saved_minor' => $goal->saved_minor,
            'currency' => $goal->currency,
            'target_date' => $goal->target_date?->toDateString(),
            'lock_type' => $goal->lock_type,
            'status' => $goal->status,
            'created_at' => $goal->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function notifications(User $user): array
    {
        $items = AppNotification::query()
            ->where('user_id', $user->id)
            ->latest()
            ->limit(50)
            ->get();

        return [
            'unread' => AppNotification::query()->where('user_id', $user->id)->unread()->count(),
            'items' => $items->map(fn (AppNotification $notification): array => [
                'id' => $notification->id,
                'user_id' => $notification->user_id,
                'type' => $notification->type,
                'title' => $notification->title,
                'body' => $notification->body,
                'action_tab' => $notification->action_tab,
                'data' => $notification->data,
                'read' => $notification->read_at !== null,
                'created_at' => $notification->created_at?->toIso8601String(),
            ])->all(),
        ];
    }

    /**
     * Linked students with the balance each one still owes.
     *
     * @return list<array<string, mixed>>
     */
    public function children(User $user): array
    {
        return $user->children()
            ->with('school:id,name,short_name,address')
            ->get()
            ->map(function (User $child): array {
                $wallet = $this->wallets->forUser($child);
                $outstanding = Invoice::query()
                    ->where('student_id', $child->id)
                    ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                    ->get()
                    ->sum(fn (Invoice $invoice) => $this->payments->outstandingFor($invoice));

                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'email' => $child->email,
                    'matric_number' => $child->profile['matric_number'] ?? null,
                    'school' => $child->school?->name ?? ($child->profile['school_name'] ?? null),
                    'school_id' => $child->school_id,
                    'school_address' => $child->school?->address,
                    'level' => $child->profile['level'] ?? null,
                    'category' => $child->profile['category'] ?? 'tertiary',
                    'is_minor' => (bool) ($child->profile['is_minor'] ?? false),
                    'consent_given' => (bool) ($child->profile['consent_given'] ?? true),
                    'wallet' => $wallet,
                    'wallet_balance_minor' => $wallet['available_minor'],
                    'outstanding_minor' => (int) $outstanding,
                    'kyc_status' => $child->kyc_status,
                    'bank_account' => $this->banks->present($this->banks->forUser($child)),
                ];
            })
            ->all();
    }

    /**
     * School-wide figures, computed from invoices and the ledger.
     *
     * @return array<string, mixed>
     */
    public function schoolAdmin(School $school): array
    {
        $students = User::query()
            ->where('school_id', $school->id)
            ->where('role', 'student')
            ->orderBy('name')
            ->get();

        $invoices = Invoice::query()->where('school_id', $school->id)->get();

        $roster = $students->map(function (User $student) use ($invoices) {
            $own = $invoices->where('student_id', $student->id);
            $billed = (int) $own->sum('amount_minor');
            $paid = (int) $own->sum(fn (Invoice $invoice) => $invoice->amount_minor - $this->payments->outstandingFor($invoice));
            $balance = max(0, $billed - $paid);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'matric_number' => $student->profile['matric_number'] ?? null,
                'department' => $student->profile['department'] ?? null,
                'level' => $student->profile['level'] ?? null,
                'parent_name' => $student->profile['parent_name'] ?? null,
                'parent_phone' => $student->profile['parent_phone'] ?? null,
                'tuition_billed_minor' => $billed,
                'tuition_paid_minor' => $paid,
                'balance_minor' => $balance,
                'status' => $balance === 0 ? 'fully_paid' : ($paid > 0 ? 'partially_paid' : 'unpaid'),
            ];
        })->all();

        $wallet = $this->wallets->forSchool($school);
        $billed = (int) collect($roster)->sum('tuition_billed_minor');
        $paid = (int) collect($roster)->sum('tuition_paid_minor');

        return [
            'students' => $roster,
            'metrics' => [
                'student_count' => count($roster),
                'total_billed_minor' => $billed,
                'total_collected_minor' => $paid,
                'outstanding_minor' => max(0, $billed - $paid),
                'collection_rate_percent' => $billed > 0 ? round(($paid / $billed) * 100, 1) : 0.0,
                'available_wallet_minor' => $wallet['available_minor'],
                'currency' => $wallet['currency'],
            ],
            'applications' => WorkspaceRecord::query()
                ->ofType('school_application')
                ->where('school_id', $school->id)
                ->get()
                ->pluck('data')
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function admin(): array
    {
        $invoices = Invoice::query()->get();
        $collected = (int) $invoices->sum(fn (Invoice $invoice) => $invoice->amount_minor - $this->payments->outstandingFor($invoice));

        return [
            'overview' => [
                'users' => User::query()->count(),
                'students' => User::query()->where('role', 'student')->count(),
                'schools' => School::query()->count(),
                'invoices' => $invoices->count(),
                'billed_minor' => (int) $invoices->sum('amount_minor'),
                'collected_minor' => $collected,
                'outstanding_minor' => max(0, (int) $invoices->sum('amount_minor') - $collected),
                'campaigns' => Campaign::query()->count(),
                'campaigns_pending_review' => Campaign::query()->whereIn('status', ['submitted', 'in_review'])->count(),
                'currency' => 'NGN',
            ],
            'users' => User::query()->orderBy('id')->get()->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'school_id' => $user->school_id,
                'email_verified' => $user->email_verified_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
            ])->all(),
            'schools' => School::query()->withCount('users')->get()->map(fn (School $school): array => array_merge(
                (array) $this->school($school),
                ['users_count' => $school->users_count]
            ))->all(),
            'audit_logs' => $this->records('audit_log'),
            'disputes' => $this->records('dispute'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ngo(User $user): array
    {
        return [
            'team' => $this->records('ngo_team', $user),
            'initiatives' => $this->records('ngo_initiative', $user),
            'beneficiaries' => $this->records('ngo_beneficiary', $user),
            'donations' => $this->records('ngo_donation', $user),
            'impact' => $this->records('ngo_impact', $user)->first()['data'] ?? null,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function records(string $type, ?User $owner = null)
    {
        return WorkspaceRecord::query()
            ->ofType($type)
            ->when($owner !== null, fn ($query) => $query->where(
                fn ($q) => $q->where('owner_id', $owner->id)->orWhereNull('owner_id')
            ))
            ->orderBy('id')
            ->get()
            ->map(fn (WorkspaceRecord $record): array => [
                'id' => $record->key ?? (string) $record->id,
                'status' => $record->status,
                'data' => $record->data,
            ]);
    }
}
