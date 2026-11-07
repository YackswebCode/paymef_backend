<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\LedgerEntry;
use App\Models\WorkspaceRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * NGO / foundation workspace.
 *
 * Records live in `workspace_records` as typed documents so a programme, a
 * beneficiary and a grant share one auditable persistence path without a table
 * per concept. Money movements still go through the ledger.
 */
class NgoController extends Controller
{
    public function initiatives(Request $request): JsonResponse
    {
        return response()->json(['initiatives' => $this->documents('ngo_initiative', $request->user()->id)]);
    }

    public function storeInitiative(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:4000'],
            'focusArea' => ['nullable', 'string', 'max:40'],
            'targetTier' => ['nullable', 'string', 'max:40'],
            'totalBudget' => ['required', 'numeric', 'min:1'],
            'targetBeneficiaries' => ['nullable', 'integer', 'min:1'],
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date'],
            'matchingMultiplier' => ['nullable', 'numeric', 'min:0'],
            'targetedInstitutions' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $key = 'ngo_ini_'.bin2hex(random_bytes(5));

        $initiative = array_merge([
            'id' => $key,
            'ngoId' => (string) $user->id,
            'code' => 'HSA-'.strtoupper(substr($key, -5)),
            'status' => 'planned',
            'disbursedBudget' => 0,
            'committedBudget' => 0,
            'beneficiaryCount' => 0,
            'currency' => 'NGN',
            'milestones' => [],
            'createdAt' => now()->toIso8601String(),
        ], $data);

        $this->storeDocument('ngo_initiative', $key, $initiative, $user->id);

        return response()->json(['initiative' => $initiative, 'message' => 'Initiative created.'], 201);
    }

    public function updateInitiativeStatus(Request $request, string $initiative): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'in:active,planned,paused,completed']]);

        $record = $this->findDocument('ngo_initiative', $initiative);
        $payload = array_merge($record->data, ['status' => $data['status']]);
        $record->update(['data' => $payload]);

        return response()->json(['initiative' => $payload, 'message' => "Initiative status updated to {$data['status']}."]);
    }

    public function beneficiaries(Request $request): JsonResponse
    {
        return response()->json(['beneficiaries' => $this->documents('ngo_beneficiary', $request->user()->id)]);
    }

    public function storeBeneficiary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'studentName' => ['required', 'string', 'max:160'],
            'matricNumber' => ['required', 'string', 'max:60'],
            'schoolName' => ['required', 'string', 'max:160'],
            'schoolId' => ['nullable', 'string', 'max:60'],
            'educationTier' => ['nullable', 'in:secondary,tertiary'],
            'courseOrGrade' => ['nullable', 'string', 'max:160'],
            'initiativeId' => ['nullable', 'string', 'max:60'],
            'initiativeTitle' => ['nullable', 'string', 'max:160'],
            'awardedGrantAmount' => ['nullable', 'numeric', 'min:0'],
            'gender' => ['nullable', 'in:female,male'],
        ]);

        $key = 'ngo_ben_'.bin2hex(random_bytes(5));

        $beneficiary = array_merge([
            'id' => $key,
            'studentId' => null,
            'disbursedToDate' => 0,
            'academicStanding' => 'pending_verification',
            'verificationStatus' => 'pending_docs',
            'gpa' => '—',
            'academicSession' => '2025/2026 Academic Session',
            'disbursementHistory' => [],
            'verificationDocuments' => [],
            'lastAuditNote' => 'Enrolled from the NGO console; awaiting transcript verification.',
            'enrolledAt' => now()->toIso8601String(),
            'gender' => 'female',
        ], $data);

        $this->storeDocument('ngo_beneficiary', $key, $beneficiary, $request->user()->id);

        return response()->json(['beneficiary' => $beneficiary, 'message' => 'Beneficiary enrolled.'], 201);
    }

    public function verifyBeneficiary(Request $request, string $beneficiary): JsonResponse
    {
        $data = $request->validate([
            'verificationStatus' => ['nullable', 'in:verified,pending_docs,flagged,graduated'],
            'academicStanding' => ['nullable', 'in:distinction,in_good_standing,probation,pending_verification'],
            'gpa' => ['nullable', 'string', 'max:20'],
            'auditNote' => ['nullable', 'string', 'max:1000'],
        ]);

        $record = $this->findDocument('ngo_beneficiary', $beneficiary);
        $payload = array_merge($record->data, array_filter([
            'verificationStatus' => $data['verificationStatus'] ?? null,
            'academicStanding' => $data['academicStanding'] ?? null,
            'gpa' => $data['gpa'] ?? null,
            'lastAuditNote' => $data['auditNote'] ?? null,
        ], fn ($value) => $value !== null));

        $record->update(['data' => $payload, 'status' => $payload['verificationStatus']]);

        return response()->json(['beneficiary' => $payload, 'message' => 'Verification record updated.']);
    }

    public function disburse(Request $request, string $beneficiary): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'term' => ['nullable', 'string', 'max:120'],
            'recipientType' => ['nullable', 'in:school_bursary_direct,student_stipend_wallet'],
        ]);

        $record = $this->findDocument('ngo_beneficiary', $beneficiary);
        $amount = (int) round(((float) $data['amount']) * 100);
        $awarded = (int) round(((float) ($record->data['awardedGrantAmount'] ?? 0)) * 100);
        $disbursed = (int) round(((float) ($record->data['disbursedToDate'] ?? 0)) * 100);

        abort_if($awarded > 0 && $disbursed + $amount > $awarded, 422, 'This disbursement exceeds the awarded grant.');

        $reference = 'PMF-BURS-NGO-'.strtoupper(bin2hex(random_bytes(4)));

        DB::transaction(function () use ($record, $amount, $reference, $data) {
            $history = $record->data['disbursementHistory'] ?? [];
            array_unshift($history, [
                'id' => 'disb_'.bin2hex(random_bytes(4)),
                'date' => now()->toIso8601String(),
                'amount' => $amount / 100,
                'term' => $data['term'] ?? 'Session Educational Grant',
                'recipientType' => $data['recipientType'] ?? 'school_bursary_direct',
                'reference' => $reference,
                'status' => 'completed',
                'verifiedBy' => 'PayMef NGO console',
            ]);

            $record->update(['data' => array_merge($record->data, [
                'disbursementHistory' => $history,
                'disbursedToDate' => ($record->data['disbursedToDate'] ?? 0) + $amount / 100,
            ])]);

            LedgerEntry::create([
                'user_id' => $record->owner_id,
                'entry_type' => 'debit',
                'amount_minor' => $amount,
                'currency' => 'NGN',
                'source_type' => 'ngo_grant_disbursal',
                'source_id' => $record->key ?? (string) $record->id,
                'idempotency_key' => 'ngo_disb_'.bin2hex(random_bytes(8)),
                'meta' => ['beneficiary' => $record->data['studentName'] ?? null, 'reference' => $reference],
                'occurred_at' => now(),
            ]);
        });

        return response()->json([
            'beneficiary' => $record->fresh()->data,
            'reference' => $reference,
            'message' => 'Disbursement recorded and written to the ledger.',
        ]);
    }

    public function donations(Request $request): JsonResponse
    {
        return response()->json(['donations' => $this->documents('ngo_donation', $request->user()->id)]);
    }

    public function storeDonation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'donorName' => ['required', 'string', 'max:160'],
            'donorEmail' => ['nullable', 'email'],
            'donorType' => ['nullable', 'string', 'max:40'],
            'amount' => ['required', 'numeric', 'min:1'],
            'initiativeId' => ['nullable', 'string', 'max:60'],
            'initiativeTitle' => ['nullable', 'string', 'max:160'],
            'paymentChannel' => ['nullable', 'string', 'max:80'],
        ]);

        $key = 'ngo_don_'.bin2hex(random_bytes(5));
        $amount = (int) round(((float) $data['amount']) * 100);

        $donation = array_merge([
            'id' => $key,
            'currency' => 'NGN',
            'timestamp' => now()->toIso8601String(),
            'reference' => 'PMF-NGO-'.strtoupper(bin2hex(random_bytes(4))),
            'receiptNumber' => 'RCPT-'.strtoupper(bin2hex(random_bytes(3))),
            'taxExemptStatus' => 'tax_deductible_certified',
            'status' => 'completed',
        ], $data);

        $this->storeDocument('ngo_donation', $key, $donation, $request->user()->id);

        LedgerEntry::create([
            'user_id' => $request->user()->id,
            'entry_type' => 'credit',
            'amount_minor' => $amount,
            'currency' => 'NGN',
            'source_type' => 'ngo_donation',
            'source_id' => $key,
            'idempotency_key' => 'ngo_don_'.$key,
            'meta' => ['donor' => $data['donorName']],
            'occurred_at' => now(),
        ]);

        return response()->json(['donation' => $donation, 'message' => 'Donation recorded.'], 201);
    }

    public function team(Request $request): JsonResponse
    {
        return response()->json(['team' => $this->documents('ngo_team', $request->user()->id)]);
    }

    public function storeTeamMember(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email'],
            'role' => ['required', 'string', 'max:40'],
            'department' => ['nullable', 'string', 'max:80'],
            'permissions' => ['nullable', 'array'],
        ]);

        $key = 'ngo_staff_'.bin2hex(random_bytes(5));

        $member = array_merge([
            'id' => $key,
            'status' => 'invited',
            'permissions' => [],
            'joinedAt' => now()->toIso8601String(),
            'lastActive' => now()->toIso8601String(),
        ], $data);

        $this->storeDocument('ngo_team', $key, $member, $request->user()->id);

        return response()->json(['staff' => $member, 'message' => 'Staff member invited.'], 201);
    }

    public function updateTeamPermissions(Request $request, string $member): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['required', 'array'],
            'role' => ['nullable', 'string', 'max:40'],
        ]);

        $record = $this->findDocument('ngo_team', $member);
        $payload = array_merge($record->data, $data);
        $record->update(['data' => $payload]);

        return response()->json(['staff' => $payload, 'message' => 'Permissions updated.']);
    }

    public function destroyTeamMember(string $member): JsonResponse
    {
        $this->findDocument('ngo_team', $member)->delete();

        return response()->json(['message' => 'Staff access revoked.']);
    }

    public function impactMetrics(Request $request): JsonResponse
    {
        $initiatives = $this->documents('ngo_initiative', $request->user()->id);
        $beneficiaries = $this->documents('ngo_beneficiary', $request->user()->id);

        $deployed = array_sum(array_map(fn ($initiative) => (float) ($initiative['disbursedBudget'] ?? 0), $initiatives));
        $female = count(array_filter($beneficiaries, fn ($beneficiary) => ($beneficiary['gender'] ?? '') === 'female'));

        return response()->json([
            'metrics' => [
                'totalDeployed' => $deployed,
                'activeInitiatives' => count(array_filter($initiatives, fn ($i) => ($i['status'] ?? '') === 'active')),
                'totalBeneficiaries' => count($beneficiaries),
                'institutionsReached' => count(array_unique(array_filter(array_map(
                    fn ($b) => $b['schoolName'] ?? null,
                    $beneficiaries
                )))),
                'graduationRatePercent' => 0,
                'femaleBeneficiaryPercent' => $beneficiaries === [] ? 0 : round(($female / count($beneficiaries)) * 100, 1),
                'retentionRatePercent' => 0,
                'stemAllocationPercent' => 0,
            ],
        ]);
    }

    /**
     * An NGO grant towards a student campaign: recorded as a donation and
     * reflected on the campaign the same way a retail donation is.
     */
    public function supportCampaign(Request $request, Campaign $campaign): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'initiativeId' => ['nullable', 'string', 'max:60'],
            'matchType' => ['nullable', 'string', 'max:40'],
        ]);

        $amountMinor = (int) round(((float) $data['amount']) * 100);

        DB::transaction(function () use ($campaign, $amountMinor, $data, $request) {
            $campaign->increment('gross_raised_minor', $amountMinor);
            $campaign->increment('donor_count');

            if ($campaign->student_id !== null) {
                LedgerEntry::create([
                    'school_id' => $campaign->school_id,
                    'user_id' => $campaign->student_id,
                    'entry_type' => 'credit',
                    'amount_minor' => $amountMinor,
                    'currency' => $campaign->currency,
                    'source_type' => 'ngo_grant',
                    'source_id' => (string) $campaign->id,
                    'idempotency_key' => 'ngo_grant_'.bin2hex(random_bytes(8)),
                    'meta' => ['initiative_id' => $data['initiativeId'] ?? null],
                    'occurred_at' => now(),
                ]);
            }

            $key = 'ngo_don_'.bin2hex(random_bytes(5));
            $this->storeDocument('ngo_donation', $key, [
                'id' => $key,
                'donorName' => $request->user()->name,
                'donorEmail' => $request->user()->email,
                'donorType' => 'foundation',
                'amount' => $amountMinor / 100,
                'currency' => $campaign->currency,
                'initiativeId' => $data['initiativeId'] ?? null,
                'initiativeTitle' => $campaign->title,
                'timestamp' => now()->toIso8601String(),
                'paymentChannel' => 'PayMef Wallet',
                'reference' => 'PMF-NGO-'.strtoupper(bin2hex(random_bytes(4))),
                'receiptNumber' => 'RCPT-'.strtoupper(bin2hex(random_bytes(3))),
                'taxExemptStatus' => 'tax_deductible_certified',
                'status' => 'completed',
            ], $request->user()->id);
        });

        return response()->json(['message' => 'NGO grant pledged to the campaign.', 'campaign_id' => $campaign->id], 201);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function documents(string $type, int $ownerId): array
    {
        return WorkspaceRecord::query()
            ->ofType($type)
            ->where(fn ($query) => $query->where('owner_id', $ownerId)->orWhereNull('owner_id'))
            ->orderBy('id')
            ->get()
            ->map(fn (WorkspaceRecord $record) => $record->data)
            ->all();
    }

    private function findDocument(string $type, string $key): WorkspaceRecord
    {
        return WorkspaceRecord::query()
            ->ofType($type)
            ->where(fn ($query) => $query->where('key', $key)->orWhere('id', ctype_digit($key) ? (int) $key : 0))
            ->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storeDocument(string $type, string $key, array $payload, ?int $ownerId = null): WorkspaceRecord
    {
        return WorkspaceRecord::create([
            'type' => $type,
            'key' => $key,
            'owner_id' => $ownerId,
            'data' => $payload,
            'status' => (string) ($payload['status'] ?? 'active'),
        ]);
    }
}
