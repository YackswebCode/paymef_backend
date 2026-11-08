<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MessagingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $conversations = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->with(['participants:id,name,role', 'latestMessage.sender:id,name'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        return response()
            ->json([
                'conversations' => $conversations
                    ->map(fn (Conversation $conversation): array => $this->present($conversation, $user))
                    ->values(),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        $this->ensureParticipant($conversation, $user);

        $messages = $conversation->messages()
            ->with('sender:id,name,role')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->reverse()
            ->values();

        $this->markReadFor($conversation, $user);

        return response()->json([
            'conversation' => $this->present($conversation->load('participants:id,name,role', 'latestMessage'), $user),
            'messages' => $messages->map(fn (Message $message): array => $this->presentMessage($message))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recipient_id' => ['required', 'integer', 'exists:users,id'],
            'subject' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        abort_if((int) $data['recipient_id'] === (int) $user->id, 422, 'You cannot message yourself.');

        /** @var User $recipient */
        $recipient = User::findOrFail($data['recipient_id']);

        $existing = Conversation::query()
            ->whereHas('participants', fn ($query) => $query->where('users.id', $user->id))
            ->whereHas('participants', fn ($query) => $query->where('users.id', $recipient->id))
            ->has('participants', '=', 2)
            ->first();

        if ($existing !== null) {
            return response()->json([
                'conversation' => $this->present($existing->load('participants:id,name,role', 'latestMessage'), $user),
            ]);
        }

        $conversation = DB::transaction(function () use ($user, $recipient, $data): Conversation {
            $conversation = Conversation::create([
                'subject' => $data['subject'] ?? 'Direct message',
                'school_id' => $user->school_id ?? $recipient->school_id,
                'created_by' => $user->id,
                'last_message_at' => now(),
            ]);

            $conversation->participants()->attach([
                $user->id => ['last_read_at' => now()],
                $recipient->id => ['last_read_at' => null],
            ]);

            return $conversation;
        });

        return response()->json([
            'conversation' => $this->present($conversation->load('participants:id,name,role', 'latestMessage'), $user),
        ], 201);
    }

    public function storeMessage(Request $request, Conversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:4000'],
        ]);

        $user = $request->user();
        $this->ensureParticipant($conversation, $user);

        $message = DB::transaction(function () use ($conversation, $user, $data): Message {
            $message = $conversation->messages()->create([
                'sender_id' => $user->id,
                'body' => $data['body'],
            ]);

            $conversation->forceFill(['last_message_at' => $message->created_at])->save();
            $this->markReadFor($conversation, $user);

            return $message;
        });

        return response()->json([
            'message' => $this->presentMessage($message->load('sender:id,name,role')),
        ], 201);
    }

    public function markRead(Request $request, Conversation $conversation): JsonResponse
    {
        $this->ensureParticipant($conversation, $request->user());
        $this->markReadFor($conversation, $request->user());

        return response()->json(['status' => true]);
    }

    public function recipients(Request $request): JsonResponse
    {
        $user = $request->user();

        $schoolIds = collect([$user->school_id])
            ->when($user->role === 'parent', fn ($ids) => $ids->merge($user->children()->pluck('school_id')))
            ->filter()
            ->unique()
            ->values();

        $recipients = User::query()
            ->where('users.id', '!=', $user->id)
            ->where(function ($query) use ($schoolIds): void {
                if ($schoolIds->isNotEmpty()) {
                    $query->whereIn('users.school_id', $schoolIds);
                }

                $query->orWhere('users.role', 'admin');
            })
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name', 'role', 'school_id'])
            ->map(fn (User $recipient): array => [
                'id' => $recipient->id,
                'name' => $recipient->name,
                'role' => $recipient->role,
                'school_id' => $recipient->school_id,
            ]);

        return response()->json(['recipients' => $recipients]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Conversation $conversation, User $user): array
    {
        $participant = $conversation->participants->firstWhere('id', $user->id);
        $lastReadAt = $participant?->pivot?->last_read_at;

        $unread = $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($lastReadAt !== null, fn ($query) => $query->where('created_at', '>', $lastReadAt))
            ->count();

        $latest = $conversation->latestMessage;

        return [
            'id' => $conversation->id,
            'subject' => $conversation->subject,
            'school_id' => $conversation->school_id,
            'last_message_at' => $conversation->last_message_at?->toIso8601String(),
            'participants' => $conversation->participants
                ->map(fn (User $participant): array => [
                    'id' => $participant->id,
                    'name' => $participant->name,
                    'role' => $participant->role,
                ])
                ->values(),
            'last_message' => $latest === null ? null : $this->presentMessage($latest),
            'unread_count' => $unread,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMessage(Message $message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->conversation_id,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name,
            'sender_role' => $message->sender?->role,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    private function ensureParticipant(Conversation $conversation, User $user): void
    {
        $isParticipant = $conversation->participants()->where('users.id', $user->id)->exists();

        if (! $isParticipant) {
            throw new NotFoundHttpException('Conversation not found.');
        }
    }

    private function markReadFor(Conversation $conversation, User $user): void
    {
        if ($conversation->participants()->where('users.id', $user->id)->exists()) {
            $conversation->participants()->updateExistingPivot($user->id, ['last_read_at' => now()]);
        }
    }
}
