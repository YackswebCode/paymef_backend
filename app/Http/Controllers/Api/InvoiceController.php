<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\BulkStoreInvoiceRequest;
use App\Http\Requests\Api\StoreInvoiceRequest;
use App\Http\Requests\Api\UpdateInvoiceRequest;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Services\Invoices\InvoiceService;
use App\Services\Payments\PaymentService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class InvoiceController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly PaymentService $payments
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $invoices = Invoice::query()
            ->visibleTo($user)
            ->when(
                $user->isAdmin() && $request->filled('school_id'),
                fn ($query) => $query->where('school_id', $request->integer('school_id'))
            )
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status'))
            )
            ->when(
                $user->hasRole('school_admin', 'admin') && $request->filled('student_id'),
                fn ($query) => $query->where('student_id', $request->integer('student_id'))
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
            'outstanding_minor' => $this->payments->outstandingFor($invoice),
        ]);
    }

    public function store(StoreInvoiceRequest $request): JsonResponse
    {
        $invoice = $this->invoices->create($request->user(), $request->validated());

        return response()->json(['invoice' => $invoice], 201);
    }

    public function bulk(BulkStoreInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        $schoolId = $data['school_id'] ?? null;

        // Administrators supply the school once for the whole batch.
        $rows = array_map(
            fn (array $row) => $schoolId !== null ? ['school_id' => $schoolId] + $row : $row,
            $data['invoices']
        );

        $created = $this->invoices->bulk($request->user(), $rows);

        return response()->json(['invoices' => $created], 201);
    }

    public function update(UpdateInvoiceRequest $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('manage', $invoice);

        if ($invoice->status === 'cancelled') {
            throw ValidationException::withMessages([
                'invoice' => 'A cancelled invoice cannot be edited.',
            ]);
        }

        $data = $request->validated();

        $paid = (int) PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'success')
            ->sum('amount_minor');

        if ($paid > 0 && (array_key_exists('amount_minor', $data) || array_key_exists('currency', $data))) {
            throw ValidationException::withMessages([
                'amount_minor' => 'The amount cannot change once a payment has been made.',
            ]);
        }

        $invoice->update($data);

        return response()->json(['invoice' => $invoice->refresh()]);
    }

    public function cancel(Invoice $invoice): JsonResponse
    {
        $this->authorize('manage', $invoice);

        $paid = (int) PaymentAttempt::query()
            ->where('invoice_id', $invoice->id)
            ->where('status', 'success')
            ->sum('amount_minor');

        if ($paid > 0) {
            throw ValidationException::withMessages([
                'invoice' => 'Refund the payments before cancelling this invoice.',
            ]);
        }

        $invoice->update(['status' => 'cancelled']);

        return response()->json(['invoice' => $invoice->refresh()]);
    }

    public function issue(Invoice $invoice): JsonResponse
    {
        $this->authorize('manage', $invoice);

        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages([
                'status' => 'Only a draft invoice can be issued.',
            ]);
        }

        $invoice->update(['status' => 'issued']);

        return response()->json(['invoice' => $invoice->refresh()]);
    }
}
