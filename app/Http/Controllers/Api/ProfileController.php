<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ChangePasswordRequest;
use App\Services\Banking\PartnerBankService;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;

class ProfileController extends Controller
{
    /**
     * Profile fields kept in the users.profile JSON column. Keeping them here
     * means every role can extend its profile without a schema change.
     */
    private const PROFILE_FIELDS = [
        'date_of_birth',
        'gender',
        'address',
        'city',
        'state',
        'country',
        'postal_code',
        'occupation',
        'employer',
        'bio',
        'avatar_url',
        'next_of_kin_name',
        'next_of_kin_phone',
        'matric_number',
        'department',
        'level',
        'website',
    ];

    public function __construct(
        private readonly KycService $kyc,
        private readonly PartnerBankService $banks
    ) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->payload($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'gender' => ['sometimes', 'nullable', 'in:female,male,other,prefer_not_to_say'],
            'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'nullable', 'string', 'max:120'],
            'state' => ['sometimes', 'nullable', 'string', 'max:120'],
            'country' => ['sometimes', 'nullable', 'string', 'max:120'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:20'],
            'occupation' => ['sometimes', 'nullable', 'string', 'max:120'],
            'employer' => ['sometimes', 'nullable', 'string', 'max:160'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'avatar_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'next_of_kin_name' => ['sometimes', 'nullable', 'string', 'max:120'],
            'next_of_kin_phone' => ['sometimes', 'nullable', 'string', 'max:30'],
            'matric_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'department' => ['sometimes', 'nullable', 'string', 'max:120'],
            'level' => ['sometimes', 'nullable', 'string', 'max:60'],
            'website' => ['sometimes', 'nullable', 'url', 'max:255'],
        ]);

        $user->fill([
            'name' => $data['name'] ?? $user->name,
            'phone' => array_key_exists('phone', $data) ? $data['phone'] : $user->phone,
        ]);

        $profile = $user->profile ?? [];

        foreach (self::PROFILE_FIELDS as $field) {
            if (array_key_exists($field, $data)) {
                $profile[$field] = $data[$field];
            }
        }

        $user->profile = $profile;
        $user->save();

        return response()->json($this->payload($user->refresh()));
    }

    public function avatar(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $user = $request->user();
        $path = $request->file('avatar')->store('avatars', 'public');

        $profile = $user->profile ?? [];
        $profile['avatar_url'] = Storage::disk('public')->url($path);
        $profile['avatar_path'] = $path;
        $user->profile = $profile;
        $user->save();

        return response()->json($this->payload($user->refresh()));
    }

    public function preferences(Request $request): JsonResponse
    {
        $user = $request->user();
        $settings = $user->settings ?? [];

        return response()->json([
            'notification_preferences' => $this->notificationPreferences($settings),
        ]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'payment_success' => ['sometimes', 'boolean'],
            'invoice_due' => ['sometimes', 'boolean'],
            'campaign_update' => ['sometimes', 'boolean'],
            'marketing' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'boolean'],
            'sms' => ['sometimes', 'boolean'],
            'push' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $settings = $user->settings ?? [];
        $settings['notifications'] = array_merge($this->notificationPreferences($settings), $data);
        $user->settings = $settings;
        $user->save();

        return response()->json([
            'notification_preferences' => $settings['notifications'],
        ]);
    }

    public function password(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();

        $user->forceFill(['password' => $request->validated('password')])->save();

        // Every other device is signed out; the caller keeps their own token.
        $current = $user->currentAccessToken();
        $currentId = $current instanceof PersonalAccessToken ? $current->getKey() : null;

        $user->tokens()
            ->when($currentId, fn ($query) => $query->whereKeyNot($currentId))
            ->delete();

        return response()->json(['message' => 'Password updated. Other sessions have been signed out.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(\App\Models\User $user): array
    {
        $user->loadMissing('school');
        $profile = $user->profile ?? [];

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role,
                'school_id' => $user->school_id,
                'email_verified' => $user->email_verified_at !== null,
                'avatar_url' => $profile['avatar_url'] ?? null,
                'profile' => $profile,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'school' => $user->school?->only([
                'id', 'name', 'short_name', 'slug', 'type', 'email', 'phone',
                'address', 'city', 'state', 'country', 'accreditation_number',
                'verification_status',
            ]),
            'kyc' => $this->kyc->summary($user),
            'bank_account' => $this->banks->present($this->banks->forUser($user)),
            'notification_preferences' => $this->notificationPreferences($user->settings ?? []),
            'security' => [
                'two_factor_enabled' => (bool) (($user->settings ?? [])['two_factor_enabled'] ?? false),
                'last_login_at' => $user->updated_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, bool>
     */
    private function notificationPreferences(array $settings): array
    {
        $defaults = [
            'payment_success' => true,
            'invoice_due' => true,
            'campaign_update' => true,
            'marketing' => false,
            'email' => true,
            'sms' => false,
            'push' => true,
        ];

        return array_merge($defaults, (array) ($settings['notifications'] ?? []));
    }
}
