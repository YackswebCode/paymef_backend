<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkStoreStudentRequest;
use App\Http\Requests\Api\StoreStudentRequest;
use App\Mail\AccountInviteMail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $students = User::query()
            ->where('role', 'student')
            ->when(
                $user->isAdmin(),
                fn ($query) => $query->when(
                    $request->filled('school_id'),
                    fn ($query) => $query->where('school_id', $request->integer('school_id'))
                ),
                function ($query) use ($user) {
                    abort_if($user->school_id === null, 403, 'Your account is not attached to a school.');

                    return $query->where('school_id', $user->school_id);
                }
            )
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = '%'.$request->string('q').'%';

                return $query->where(fn ($query) => $query
                    ->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderBy('name')
            ->paginate(25);

        return response()->json($students);
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $schoolId = $this->resolveSchoolId($request->user(), $request->validated('school_id'));

        if (User::query()->where('email', Str::lower($request->validated('email')))->exists()) {
            throw ValidationException::withMessages([
                'email' => 'An account with this email already exists.',
            ]);
        }

        $student = $this->createStudent($schoolId, $request->validated('name'), $request->validated('email'));

        return response()->json(['student' => $student], 201);
    }

    public function bulk(BulkStoreStudentRequest $request): JsonResponse
    {
        $schoolId = $this->resolveSchoolId($request->user(), $request->validated('school_id'));

        $created = [];
        $skipped = [];

        foreach ($request->validated('students') as $row) {
            $email = Str::lower($row['email']);

            if (User::query()->where('email', $email)->exists()) {
                $skipped[] = $email;

                continue;
            }

            $created[] = $this->createStudent($schoolId, $row['name'], $email);
        }

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
        ], 201);
    }

    private function createStudent(int $schoolId, string $name, string $email): User
    {
        $student = User::create([
            'name' => $name,
            'email' => Str::lower($email),
            'password' => Str::random(40),
            'role' => 'student',
            'school_id' => $schoolId,
        ]);

        // Provisioned by their school, which vouches for the address; the invite
        // token is what proves control of it when they choose a password.
        $student->forceFill(['email_verified_at' => now()])->save();

        Mail::to($student->email)->queue(new AccountInviteMail($student, 'student'));

        return $student->refresh();
    }

    private function resolveSchoolId(User $user, mixed $requestedSchoolId): int
    {
        if ($user->isAdmin()) {
            if (empty($requestedSchoolId)) {
                throw ValidationException::withMessages([
                    'school_id' => 'The school_id field is required for administrators.',
                ]);
            }

            return (int) $requestedSchoolId;
        }

        if ($user->school_id === null) {
            abort(403, 'Your account is not attached to a school.');
        }

        if ($requestedSchoolId !== null && (int) $requestedSchoolId !== (int) $user->school_id) {
            abort(403, 'You can only manage students in your own school.');
        }

        return (int) $user->school_id;
    }
}
