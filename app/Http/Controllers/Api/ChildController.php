<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChildController extends Controller
{
    use AuthorizesRequests;

    /**
     * Invoice states that still owe money.
     */
    private const OPEN_STATUSES = ['issued', 'partially_paid', 'overdue'];

    public function __construct(private readonly PaymentService $payments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewChildren', User::class);

        $parent = $request->user();

        $children = Guardian::query()
            ->with('student.school:id,name,slug')
            ->where('parent_id', $parent->id)
            ->get()
            ->map(fn (Guardian $guardian) => [
                'relationship' => $guardian->relationship,
                'student' => [
                    'id' => $guardian->student->id,
                    'name' => $guardian->student->name,
                    'email' => $guardian->student->email,
                    'school' => $guardian->student->school?->only(['id', 'name', 'slug']),
                ],
                'outstanding_minor' => $this->outstandingFor($guardian->student),
            ]);

        return response()->json(['children' => $children]);
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
