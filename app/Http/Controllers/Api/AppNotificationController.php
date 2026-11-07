<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppNotificationController extends Controller
{
    /** Channel toggles each account starts with. */
    private const DEFAULT_PREFERENCES = [
        'global' => ['in_app' => true, 'email' => true, 'sms' => false, 'whatsapp' => false],
        'events' => [
            'payments' => ['in_app' => true, 'email' => true, 'sms' => false, 'whatsapp' => false],
            'security' => ['in_app' => true, 'email' => true, 'sms' => true, 'whatsapp' => false],
            'campaigns' => ['in_app' => true, 'email' => true, 'sms' => false, 'whatsapp' => false],
            'school' => ['in_app' => true, 'email' => true, 'sms' => false, 'whatsapp' => false],
            'banking' => ['in_app' => true, 'email' => true, 'sms' => false, 'whatsapp' => false],
        ],
        'marketing_allowed' => false,
    ];

    public function index(Request $request): JsonResponse
    {
        $notifications = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->when($request->boolean('unread'), fn ($query) => $query->unread())
            ->when($request->filled('type'), fn ($query) => $query->where('type', $request->string('type')))
            ->latest()
            ->limit(100)
            ->get();

        return response()->json([
            'notifications' => $notifications->map(fn (AppNotification $notification): array => [
                'id' => (string) $notification->id,
                'recipientUserId' => (string) $notification->user_id,
                'eventType' => $notification->type,
                'title' => $notification->title,
                'message' => $notification->body,
                'severity' => $notification->data['severity'] ?? 'info',
                'actionTab' => $notification->action_tab,
                'channels' => $notification->data['channels'] ?? [
                    ['channel' => 'in_app', 'status' => 'delivered'],
                ],
                'read' => $notification->read_at !== null,
                'readAt' => $notification->read_at?->toIso8601String(),
                'createdAt' => $notification->created_at?->toIso8601String(),
                'metadata' => $notification->data['metadata'] ?? null,
            ])->all(),
            'unread' => AppNotification::query()->where('user_id', $request->user()->id)->unread()->count(),
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'count' => AppNotification::query()->where('user_id', $request->user()->id)->unread()->count(),
        ]);
    }

    public function markRead(Request $request, AppNotification $notification): JsonResponse
    {
        abort_unless((int) $notification->user_id === (int) $request->user()->id, 403);

        $notification->update(['read_at' => $notification->read_at ?? now()]);

        return response()->json(['notification' => ['id' => $notification->id, 'read' => true]]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = AppNotification::query()
            ->where('user_id', $request->user()->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    public function preferences(Request $request): JsonResponse
    {
        return response()->json(['preferences' => $this->preferencesFor($request->user())]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'global' => ['sometimes', 'array'],
            'events' => ['sometimes', 'array'],
            'marketing_allowed' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        $settings = $user->settings ?? [];
        $settings['notifications'] = array_replace_recursive($this->preferencesFor($user), $data);
        $user->update(['settings' => $settings]);

        return response()->json(['preferences' => $this->preferencesFor($user->fresh())]);
    }

    /**
     * Developer utility: drop a sample notification so the bell has something to
     * verify against in each environment.
     */
    public function testEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'eventType' => ['nullable', 'string', 'max:40'],
            'title' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:500'],
        ]);

        $notification = AppNotification::create([
            'user_id' => $request->user()->id,
            'type' => $data['eventType'] ?? 'security_alert',
            'title' => $data['title'] ?? 'Test notification',
            'body' => $data['message'] ?? 'This is a test event generated from your PayMef account.',
            'action_tab' => 'notifications',
            'data' => ['severity' => 'info', 'metadata' => ['source' => 'test-event']],
        ]);

        return response()->json(['notification' => ['id' => $notification->id]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function preferencesFor(User $user): array
    {
        $stored = $user->settings['notifications'] ?? [];

        return array_replace_recursive(self::DEFAULT_PREFERENCES, $stored);
    }
}
