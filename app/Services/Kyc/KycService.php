<?php

namespace App\Services\Kyc;

use App\Models\KycVerification;
use App\Models\User;
use App\Services\Bytid\BytidClient;
use App\Services\Bytid\BytidException;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Runs identity checks and records the outcome.
 *
 * Raw BVN/NIN numbers are never persisted: only the provider's masked form and
 * the normalised record (name, date of birth, state of origin) are kept.
 */
class KycService
{
    private const RECORD_FIELDS = [
        'first_name',
        'middle_name',
        'last_name',
        'full_name',
        'date_of_birth',
        'gender',
        'phone_number',
        'state_of_origin',
        'nationality',
    ];

    public function __construct(private readonly BytidClient $bytid) {}

    /**
     * @param  array{type: string, id_number: string, first_name?: ?string, last_name?: ?string, dob?: ?string, consent: bool}  $input
     */
    public function verify(User $user, array $input): KycVerification
    {
        if (! $input['consent']) {
            throw ValidationException::withMessages([
                'consent' => 'You must consent to the identity check before submitting.',
            ]);
        }

        if ($user->kyc_status === 'verified') {
            throw ValidationException::withMessages([
                'identity' => 'Your identity is already verified.',
            ]);
        }

        $type = strtolower($input['type']);
        $idNumber = preg_replace('/\s+/', '', $input['id_number']) ?? '';

        // One check at a time per user; a stuck pending row would block retries.
        $pending = $user->kycVerifications()
            ->where('status', 'pending')
            ->where('created_at', '>', now()->subMinutes(2))
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'identity' => 'A verification is already in progress. Please wait a moment.',
            ]);
        }

        $verification = KycVerification::create([
            'user_id' => $user->id,
            'provider' => 'bytid',
            'reference' => 'pmf-kyc-'.$user->id.'-'.Str::lower(Str::random(12)),
            'check_type' => $type,
            'id_number_masked' => $this->mask($idNumber),
            'status' => 'pending',
        ]);

        $payload = array_filter([
            'id_number' => $idNumber,
            'first_name' => $input['first_name'] ?? null,
            'last_name' => $input['last_name'] ?? null,
            'dob' => $input['dob'] ?? null,
            'reference' => $verification->reference,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = $this->bytid->verifyIdentity($type, $payload);
        } catch (BytidException $e) {
            $verification->update([
                'status' => 'failed',
                'failure_reason' => Str::limit($e->getMessage(), 240),
                'raw_meta' => ['error_code' => $e->errorCode(), 'http_status' => $e->status()],
            ]);

            report($e);

            throw ValidationException::withMessages(['identity' => $e->getMessage()]);
        }

        $data = (array) ($response['data'] ?? []);
        $status = $this->mapStatus((string) ($data['status'] ?? ''));
        $verified = $status === 'verified';

        $verification->update([
            'status' => $status,
            'id_number_masked' => $data['id_number'] ?? $verification->id_number_masked,
            'field_matches' => $this->fieldMatches($data),
            'record' => $verified ? $this->normaliseRecord($data) : null,
            'face_match' => $data['face_match'] ?? null,
            'raw_meta' => [
                'provider_id' => $data['id'] ?? null,
                'environment' => $data['environment'] ?? null,
                'service' => $data['service'] ?? null,
                'amount_charged' => $data['amount_charged'] ?? null,
                'currency' => $data['currency'] ?? null,
                'request_id' => $response['request_id'] ?? null,
                'message' => $data['message'] ?? null,
            ],
            'failure_reason' => $verified ? null : Str::limit((string) ($data['message'] ?? 'No record found.'), 240),
            'verified_at' => $verified ? now() : null,
        ]);

        if ($verified) {
            $user->forceFill([
                'kyc_status' => 'verified',
                'kyc_tier' => 2,
                'kyc_verified_at' => now(),
            ])->save();
        } elseif ($user->kyc_status !== 'verified') {
            $user->forceFill(['kyc_status' => $status])->save();
        }

        return $verification->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $latest = $user->kycVerifications()->latest('id')->first();
        $status = $user->kyc_status ?: 'unverified';
        $tier = (int) $user->kyc_tier;

        return [
            'status' => $status,
            'tier' => $tier,
            'tier_label' => match (true) {
                $tier >= 2 => 'Tier 2 · Identity verified',
                $tier === 1 => 'Tier 1 · Basic',
                default => 'Tier 0 · Unverified',
            },
            'verified_at' => $user->kyc_verified_at?->toIso8601String(),
            'provider' => 'bytid',
            'provider_enabled' => $this->bytid->isConfigured(),
            'can_verify' => $this->bytid->isConfigured() && $status !== 'verified',
            'latest' => $latest ? $this->present($latest) : null,
            'checks' => $this->history($user),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function history(User $user): array
    {
        return $user->kycVerifications()
            ->latest('id')
            ->limit(10)
            ->get()
            ->map(fn (KycVerification $verification) => $this->present($verification))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function present(KycVerification $verification): array
    {
        return [
            'id' => $verification->id,
            'reference' => $verification->reference,
            'type' => $verification->check_type,
            'id_number_masked' => $verification->id_number_masked,
            'status' => $verification->status,
            'field_matches' => $verification->field_matches,
            'identity' => $verification->record,
            'failure_reason' => $verification->failure_reason,
            'verified_at' => $verification->verified_at?->toIso8601String(),
            'submitted_at' => $verification->created_at?->toIso8601String(),
        ];
    }

    /**
     * Apply a provider webhook result to the stored check.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyProviderEvent(array $data): ?KycVerification
    {
        $reference = (string) ($data['reference'] ?? '');
        $providerId = (string) ($data['id'] ?? '');

        $verification = KycVerification::query()
            ->when($reference !== '', fn ($query) => $query->where('reference', $reference))
            ->when($reference === '' && $providerId !== '', function ($query) use ($providerId) {
                $query->where('raw_meta->provider_id', $providerId);
            })
            ->latest('id')
            ->first();

        if ($verification === null) {
            return null;
        }

        $status = $this->mapStatus((string) ($data['status'] ?? ''));
        $verified = $status === 'verified';

        $verification->update([
            'status' => $status,
            'verified_at' => $verified ? ($verification->verified_at ?? now()) : null,
            'failure_reason' => $verified ? null : ($data['message'] ?? $verification->failure_reason),
        ]);

        if ($verified) {
            $verification->user->forceFill([
                'kyc_status' => 'verified',
                'kyc_tier' => 2,
                'kyc_verified_at' => $verification->verified_at ?? now(),
            ])->save();
        }

        return $verification;
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'verified' => 'verified',
            'not_found' => 'not_found',
            default => 'failed',
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, bool|null>
     */
    private function fieldMatches(array $data): array
    {
        $matches = (array) ($data['field_matches'] ?? []);

        return [
            'first_name' => $matches['first_name'] ?? null,
            'last_name' => $matches['last_name'] ?? null,
            'date_of_birth' => $matches['date_of_birth'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normaliseRecord(array $data): array
    {
        $record = (array) ($data['data'] ?? []);

        return array_filter(
            Collection::make($record)->only(self::RECORD_FIELDS)->all(),
            fn ($value) => $value !== null
        );
    }

    private function mask(string $idNumber): string
    {
        $length = strlen($idNumber);

        if ($length <= 6) {
            return str_repeat('*', $length);
        }

        return substr($idNumber, 0, 3).str_repeat('*', max(3, $length - 6)).substr($idNumber, -3);
    }
}
