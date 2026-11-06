<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\RefundPaymentRequest;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentService;
use App\Services\Payments\RefundService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly RefundService $refunds
    ) {}

    public function index(Request $request): JsonResponse
    {
        $attempts = PaymentAttempt::query()
            ->with(['invoice:id,reference,description,student_id,school_id', 'payer:id,name,email'])
            ->withSum(['refunds as refunded_minor' => fn ($query) => $query->whereIn('status', ['pending', 'processed'])], 'amount_minor')
            ->whereHas('invoice', fn ($query) => $query->visibleTo($request->user()))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('invoice_id'), fn ($query) => $query->where('invoice_id', $request->integer('invoice_id')))
            ->latest('id')
            ->paginate(20);

        return response()->json($attempts);
    }

    public function store(Request $request, Invoice $invoice): JsonResponse
    {
        $this->authorize('pay', $invoice);

        $data = $request->validate([
            'amount_minor' => ['nullable', 'integer', 'min:1'],
        ]);

        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey !== null) {
            $idempotencyKey = trim($idempotencyKey);

            if ($idempotencyKey === '' || mb_strlen($idempotencyKey) > 255) {
                throw ValidationException::withMessages([
                    'idempotency_key' => 'The Idempotency-Key header must be between 1 and 255 characters.',
                ]);
            }
        }

        $attempt = $this->payments->initialize(
            $invoice,
            $request->user(),
            $data['amount_minor'] ?? null,
            $idempotencyKey
        );

        return response()->json([
            'payment' => [
                'reference' => $attempt->provider_reference,
                'status' => $attempt->status,
                'amount_minor' => $attempt->amount_minor,
                'currency' => $attempt->currency,
                'authorization_url' => $attempt->provider_payload['authorization_url'] ?? null,
                'access_code' => $attempt->provider_payload['access_code'] ?? null,
            ],
        ], 201);
    }

    public function show(string $reference): JsonResponse
    {
        $attempt = PaymentAttempt::where('provider_reference', $reference)->firstOrFail();

        $this->authorize('view', $attempt->invoice);

        // Used as the redirect/callback endpoint: re-check with Paystack rather
        // than trusting a browser-supplied status.
        if (in_array($attempt->status, ['initialized', 'pending'], true)) {
            try {
                $attempt = $this->payments->confirm($reference) ?? $attempt;
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'payment' => [
                'reference' => $attempt->provider_reference,
                'status' => $attempt->status,
                'amount_minor' => $attempt->amount_minor,
                'currency' => $attempt->currency,
                'invoice_id' => $attempt->invoice_id,
                'invoice_reference' => $attempt->invoice?->reference,
                'payer_id' => $attempt->payer_id,
                'refunded_minor' => (int) $attempt->refunds()->whereIn('status', ['pending', 'processed'])->sum('amount_minor'),
                'created_at' => $attempt->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function refund(RefundPaymentRequest $request, string $reference): JsonResponse
    {
        $attempt = PaymentAttempt::where('provider_reference', $reference)->firstOrFail();

        $this->authorize('refund', $attempt->invoice);

        $refund = $this->refunds->request(
            $attempt,
            $request->user(),
            $request->validated('amount_minor'),
            $request->validated('reason')
        );

        return response()->json([
            'refund' => [
                'id' => $refund->id,
                'amount_minor' => $refund->amount_minor,
                'currency' => $refund->currency,
                'status' => $refund->status,
                'reason' => $refund->reason,
                'payment_reference' => $attempt->provider_reference,
                'invoice_id' => $refund->invoice_id,
            ],
        ], 201);
    }
}
