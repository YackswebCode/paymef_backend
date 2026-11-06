<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreInvoiceRequest;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): JsonResponse
    {
        $invoices = Invoice::query()
            ->visibleTo($request->user())
            ->when(
                $request->user()->isAdmin() && $request->filled('school_id'),
                fn ($query) => $query->where('school_id', $request->integer('school_id'))
            )
            ->latest('id')
            ->paginate(20);

        return response()->json($invoices);
    }

    public function show(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('view', $invoice);

        return response()->json([
            'invoice' => $invoice->load('school:id,name,slug', 'student:id,name,email'),
        ]);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        if ($user->isAdmin()) {
            if (empty($data['school_id'])) {
                throw ValidationException::withMessages([
                    'school_id' => 'The school_id field is required for administrators.',
                ]);
            }

            $schoolId = (int) $data['school_id'];
        } else {
            if ($user->school_id === null) {
                abort(403, 'Your account is not attached to a school.');
            }

            // A school admin can never redirect an invoice to another school.
            if (isset($data['school_id']) && (int) $data['school_id'] !== (int) $user->school_id) {
                abort(403, 'You can only create invoices for your own school.');
            }

            $schoolId = (int) $user->school_id;
        }

        $student = User::query()
            ->whereKey($data['student_id'])
            ->where('role', 'student')
            ->first();

        if (! $student) {
            throw ValidationException::withMessages([
                'student_id' => 'The selected student is invalid.',
            ]);
        }

        if ((int) $student->school_id !== $schoolId) {
            throw ValidationException::withMessages([
                'student_id' => 'The student does not belong to this school.',
            ]);
        }

        $invoice = Invoice::create([
            'school_id' => $schoolId,
            'student_id' => $student->id,
            'reference' => $data['reference'] ?? 'INV-'.Str::upper(Str::random(10)),
            'amount_minor' => $data['amount_minor'],
            'currency' => $data['currency'] ?? 'NGN',
            'status' => $data['status'] ?? 'issued',
            'due_date' => $data['due_date'] ?? null,
        ]);

        return response()->json(['invoice' => $invoice], 201);
    }
}
