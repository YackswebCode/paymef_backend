<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreSchoolAdminRequest;
use App\Http\Requests\Api\StoreSchoolRequest;
use App\Http\Requests\Api\UpdateSchoolRequest;
use App\Mail\AccountInviteMail;
use App\Models\Invoice;
use App\Models\PaymentAttempt;
use App\Models\Refund;
use App\Models\School;
use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SchoolController extends Controller
{
    use AuthorizesRequests;

    /**
     * Invoice states that still owe money.
     */
    private const OPEN_STATUSES = ['issued', 'partially_paid', 'overdue'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $schools = School::query()
            ->when(! $user->isAdmin(), fn ($query) => $query->whereKey($user->school_id))
            ->orderBy('name')
            ->paginate(50);

        return response()->json($schools);
    }

    public function store(StoreSchoolRequest $request): JsonResponse
    {
        abort_unless($request->user()->isAdmin(), 403, 'Only administrators can create schools.');

        $data = $request->validated();

        $school = School::create([
            'name' => $data['name'],
            'slug' => $data['slug'] ?? Str::slug($data['name']),
            'verification_status' => $data['verification_status'] ?? 'pending',
        ]);

        return response()->json(['school' => $school], 201);
    }

    public function show(School $school): JsonResponse
    {
        $this->authorize('view', $school);

        return response()->json(['school' => $school]);
    }

    public function update(UpdateSchoolRequest $request, School $school): JsonResponse
    {
        $this->authorize('update', $school);

        $school->update($request->validated());

        return response()->json(['school' => $school->refresh()]);
    }

    /**
     * Provision the first (or another) administrator for a school.
     */
    public function storeAdmin(StoreSchoolAdminRequest $request, School $school): JsonResponse
    {
        $this->authorize('update', $school);

        $data = $request->validated();

        $admin = User::create([
            'name' => $data['name'],
            'email' => Str::lower($data['email']),
            'password' => Str::random(40),
            'role' => 'school_admin',
            'school_id' => $school->id,
        ]);

        // Invited by a trusted administrator, so the address is vouched for; the
        // emailed single-use token is what proves control when setting a password.
        $admin->forceFill(['email_verified_at' => now()])->save();

        Mail::to($admin->email)->queue(new AccountInviteMail($admin, 'school administrator'));

        return response()->json([
            'user' => $admin->refresh(),
            'invite_sent' => true,
        ], 201);
    }

    public function summary(School $school): JsonResponse
    {
        $this->authorize('view', $school);

        $invoices = Invoice::query()->where('school_id', $school->id);

        $invoiced = (int) (clone $invoices)->sum('amount_minor');

        $openIds = (clone $invoices)->whereIn('status', self::OPEN_STATUSES)->pluck('id');

        $openTotal = (int) Invoice::query()->whereIn('id', $openIds)->sum('amount_minor');
        $openPaid = (int) PaymentAttempt::query()
            ->whereIn('invoice_id', $openIds)
            ->where('status', 'success')
            ->sum('amount_minor');
        $openRefunded = (int) Refund::query()
            ->whereIn('invoice_id', $openIds)
            ->where('status', 'processed')
            ->sum('amount_minor');

        $collected = (int) PaymentAttempt::query()
            ->whereIn('invoice_id', (clone $invoices)->select('id'))
            ->where('status', 'success')
            ->sum('amount_minor')
            - (int) Refund::query()
                ->where('school_id', $school->id)
                ->where('status', 'processed')
                ->sum('amount_minor');

        $byStatus = (clone $invoices)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return response()->json([
            'summary' => [
                'currency' => 'NGN',
                'invoiced_minor' => $invoiced,
                'collected_minor' => $collected,
                'outstanding_minor' => max(0, $openTotal - $openPaid + $openRefunded),
                'student_count' => User::query()->where('role', 'student')->where('school_id', $school->id)->count(),
                'invoices_by_status' => $byStatus,
                'recent_payments' => PaymentAttempt::query()
                    ->with('invoice:id,reference')
                    ->whereIn('invoice_id', (clone $invoices)->select('id'))
                    ->latest('id')
                    ->limit(5)
                    ->get(['id', 'invoice_id', 'provider_reference', 'amount_minor', 'currency', 'status', 'created_at']),
            ],
        ]);
    }
}
