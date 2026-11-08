<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Banking\PartnerBankService;
use App\Services\Payments\PaymentService;
use App\Services\Wallet\WalletService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ChildController extends Controller
{
    use AuthorizesRequests;

    /**
     * Invoice states that still owe money.
     */
    private const OPEN_STATUSES = ['issued', 'partially_paid', 'overdue'];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly WalletService $wallets,
        private readonly PartnerBankService $banks
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewChildren', User::class);

        $parent = $request->user();

        $children = Guardian::query()
            ->with('student.school:id,name,slug')
            ->where('parent_id', $parent->id)
            ->get()
            ->map(fn (Guardian $guardian) => $this->present($guardian->student, $guardian->relationship));

        return response()->json(['children' => $children]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('viewChildren', User::class);

        $parent = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:160', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'school_id' => ['nullable', 'integer', 'exists:schools,id'],
            'school_name' => ['nullable', 'string', 'max:160'],
            'relationship' => ['nullable', Rule::in(['father', 'mother', 'legal_guardian', 'guardian'])],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'grade_level' => ['nullable', 'string', 'max:60'],
            'matric_number' => ['nullable', 'string', 'max:40'],
        ]);

        $schoolId = $data['school_id'] ?? $parent->school_id;

        $student = User::create([
            'name' => $data['name'],
            'email' => $data['email'] ?? 'child-'.Str::lower(Str::random(12)).'@guardian.paymef.test',
            'password' => Hash::make(Str::random(40)),
            'role' => 'student',
            'school_id' => $schoolId,
            'phone' => $data['phone'] ?? null,
            'email_verified_at' => now(),
            'profile' => array_filter([
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'grade_level' => $data['grade_level'] ?? null,
                'matric_number' => $data['matric_number'] ?? null,
                'school_name' => $data['school_name'] ?? null,
                'guardian_name' => $parent->name,
                'created_by_guardian' => true,
            ], fn ($value) => $value !== null),
        ]);

        $relationship = $data['relationship'] ?? 'guardian';

        Guardian::create([
            'school_id' => $schoolId,
            'parent_id' => $parent->id,
            'student_id' => $student->id,
            'relationship' => $relationship,
        ]);

        $this->banks->apply($parent, $student, [
            'account_type' => 'student_savings',
            'currency' => 'NGN',
            'source' => 'guardian_link',
            'regulatory_consent_accepted' => true,
            'parent_consent' => true,
        ]);

        return response()->json([
            'child' => $this->present($student->refresh(), $relationship),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(User $student, ?string $relationship = null): array
    {
        $student->loadMissing('school:id,name,slug');

        return [
            'relationship' => $relationship,
            'student' => [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'phone' => $student->phone,
                'school' => $student->school?->only(['id', 'name', 'slug']),
                'profile' => $student->profile,
                'kyc_status' => $student->kyc_status,
            ],
            'wallet' => $this->wallets->forUser($student),
            'bank_account' => $this->banks->present($this->banks->forUser($student)),
            'outstanding_minor' => $this->outstandingFor($student),
        ];
    }

    /**
     * Sum what is still owed across the student's open invoices, taking partial
     * payments and processed refunds into account.
     */
    private function outstandingFor(User $student): int
    {
        return (int) Invoice::query()
            ->where('student_id', $student->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->get()
            ->sum(fn (Invoice $invoice) => $this->payments->outstandingFor($invoice));
    }
}
