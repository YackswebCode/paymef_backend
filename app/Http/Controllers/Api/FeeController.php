<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FeeSchedule;
use App\Models\PlatformFeeConfig;
use App\Models\School;
use App\Services\Workspace\WorkspacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeController extends Controller
{
    public function __construct(private readonly WorkspacePresenter $workspace) {}

    public function showConfig(): JsonResponse
    {
        return response()->json(['fee_config' => $this->workspace->feeConfig()]);
    }

    public function updateConfig(Request $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Administrator access required.');

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'percentage_rate' => ['sometimes', 'numeric', 'min:0', 'max:0.5'],
            'fixed_fee_minor' => ['sometimes', 'integer', 'min:0'],
            'min_fee_minor' => ['sometimes', 'integer', 'min:0'],
            'category' => ['sometimes', 'string', 'max:40'],
            'effective_from' => ['sometimes', 'date'],
            'applies_to_new_only' => ['sometimes', 'boolean'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $config = PlatformFeeConfig::query()->where('is_active', true)->latest('effective_from')->first();

        if ($config === null) {
            $config = PlatformFeeConfig::create(array_merge([
                'name' => 'Standard PayMef fee',
                'percentage_rate' => 0.10,
                'effective_from' => now()->toDateString(),
                'currency' => 'NGN',
            ], $data));
        } else {
            $config->update($data);
        }

        return response()->json([
            'fee_config' => $this->workspace->feeConfig(),
            'message' => 'Fee configuration saved.',
        ]);
    }

    public function indexSchedules(Request $request): JsonResponse
    {
        $user = $request->user();

        $schoolId = $user->isAdmin()
            ? ($request->filled('schoolId') ? $request->integer('schoolId') : $request->integer('school_id'))
            : $user->school_id;

        abort_if(! $user->isAdmin() && $schoolId === null, 403, 'Your account is not attached to a school.');

        $schedules = FeeSchedule::query()
            ->when($schoolId, fn ($query) => $query->where('school_id', $schoolId))
            ->orderBy('due_date')
            ->get();

        return response()->json([
            'fee_schedules' => $schedules->map(fn (FeeSchedule $schedule): array => [
                'id' => $schedule->id,
                'school_id' => $schedule->school_id,
                'name' => $schedule->name,
                'term' => $schedule->term,
                'academic_year' => $schedule->academic_year,
                'amount_minor' => $schedule->amount_minor,
                'currency' => $schedule->currency,
                'due_date' => $schedule->due_date?->toDateString(),
                'status' => $schedule->status,
            ])->all(),
        ]);
    }

    public function storeSchedule(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->hasRole('school_admin', 'admin'), 403, 'School administrator access required.');

        $data = $request->validate([
            'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'name' => ['required', 'string', 'max:120'],
            'term' => ['required', 'string', 'max:60'],
            'academic_year' => ['required', 'string', 'max:20'],
            'amount_minor' => ['required', 'integer', 'min:100'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', 'in:draft,published,archived'],
        ]);

        $schoolId = $user->isAdmin() ? ($data['school_id'] ?? null) : $user->school_id;
        abort_if($schoolId === null, 422, 'A school is required.');
        abort_unless(School::query()->whereKey($schoolId)->exists(), 422, 'Unknown school.');

        $schedule = FeeSchedule::create([
            'school_id' => $schoolId,
            'name' => $data['name'],
            'term' => $data['term'],
            'academic_year' => $data['academic_year'],
            'amount_minor' => $data['amount_minor'],
            'due_date' => $data['due_date'] ?? null,
            'status' => $data['status'] ?? 'published',
            'created_by' => $user->id,
        ]);

        return response()->json(['fee_schedule' => $schedule], 201);
    }
}
