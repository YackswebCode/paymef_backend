<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreGuardianRequest;
use App\Models\Guardian;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GuardianController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request, User $student): JsonResponse
    {
        $this->authorize('manageGuardians', $student);

        $guardians = Guardian::query()
            ->with('parent:id,name,email')
            ->where('student_id', $student->id)
            ->latest('id')
            ->get()
            ->map(fn (Guardian $guardian) => $this->payload($guardian));

        return response()->json(['guardians' => $guardians]);
    }

    public function store(StoreGuardianRequest $request, User $student): JsonResponse
    {
        $this->authorize('manageGuardians', $student);

        $parent = User::query()->where('email', $request->validated('email'))->first();

        if (! $parent || ! $parent->hasRole('parent')) {
            throw ValidationException::withMessages([
                'email' => 'The selected account is not a parent.',
            ]);
        }

        if ((int) $parent->id === (int) $student->id) {
            throw ValidationException::withMessages([
                'email' => 'A student cannot be their own guardian.',
            ]);
        }

        $guardian = Guardian::firstOrCreate(
            ['parent_id' => $parent->id, 'student_id' => $student->id],
            [
                'school_id' => $student->school_id,
                'relationship' => $request->validated('relationship'),
            ]
        );

        return response()->json(
            ['guardian' => $this->payload($guardian->load('parent:id,name,email'))],
            $guardian->wasRecentlyCreated ? 201 : 200
        );
    }

    public function destroy(User $student, User $parent): JsonResponse
    {
        $this->authorize('manageGuardians', $student);

        Guardian::query()
            ->where('parent_id', $parent->id)
            ->where('student_id', $student->id)
            ->delete();

        return response()->json(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Guardian $guardian): array
    {
        return [
            'id' => $guardian->id,
            'relationship' => $guardian->relationship,
            'school_id' => $guardian->school_id,
            'parent' => [
                'id' => $guardian->parent->id,
                'name' => $guardian->parent->name,
                'email' => $guardian->parent->email,
            ],
        ];
    }
}
