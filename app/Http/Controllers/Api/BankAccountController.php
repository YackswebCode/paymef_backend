<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\User;
use App\Services\Banking\PartnerBankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BankAccountController extends Controller
{
    public function __construct(private readonly PartnerBankService $banks) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $accounts = collect([$this->banks->present($this->banks->forUser($user))]);

        if ($user->hasRole('parent')) {
            foreach ($user->children()->get() as $child) {
                $accounts->push($this->banks->present($this->banks->forUser($child)));
            }
        }

        return response()->json([
            'accounts' => $accounts->filter()->values(),
            'capabilities' => $this->banks->capabilities(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:users,id'],
            'account_type' => ['nullable', Rule::in(['student_savings', 'personal_savings', 'institutional_collection'])],
            'currency' => ['nullable', Rule::in(['NGN'])],
            'regulatory_consent_accepted' => ['accepted'],
            'parent_consent' => ['nullable', 'boolean'],
        ]);

        $requester = $request->user();
        $subject = $requester;

        if (! empty($data['student_id']) && (int) $data['student_id'] !== (int) $requester->id) {
            $this->authorize('viewChildren', User::class);

            $subject = $requester->children()->whereKey((int) $data['student_id'])->first();

            abort_if($subject === null, 403, 'You can only request accounts for linked students.');
        }

        $account = $this->banks->apply($requester, $subject, [
            'account_type' => $data['account_type'] ?? 'student_savings',
            'currency' => $data['currency'] ?? 'NGN',
            'regulatory_consent_accepted' => true,
            'parent_consent' => (bool) ($data['parent_consent'] ?? false),
            'requested_for' => $subject->id,
        ]);

        return response()->json([
            'account' => $this->banks->present($account),
            'capabilities' => $this->banks->capabilities(),
        ], $account->wasRecentlyCreated ? 201 : 200);
    }
}
