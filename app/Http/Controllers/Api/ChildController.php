<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\User;
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
     * Sum what is still owed across the student's open invoices, taking any
     * partially successful payments into account.
     */
    private function outstandingFor(User $student): int
    {
        $invoices = Invoice::query()
            ->where('student_id', $student->id)
            ->whereIn('status', self::OPEN_STATUSES)
            ->get(['id', 'amount_minor']);

        $paid = (int) PaymentAttempt::query()
            ->whereIn('invoice_id', $invoices->pluck('id'))
            ->where('status', 'success')
            ->sum('amount_minor');

        return max(0, (int) $invoices->sum('amount_minor') - $paid);
    }
}
