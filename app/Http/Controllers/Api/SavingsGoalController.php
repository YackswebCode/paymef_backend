<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\SavingsGoal;
use App\Services\Wallet\WalletService;
use App\Services\Workspace\WorkspacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SavingsGoalController extends Controller
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly WorkspacePresenter $workspace,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'savings_goals' => $this->workspace->savingsGoals($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'category' => ['nullable', 'in:tuition,books,living,exam,emergency'],
            'target_amount_minor' => ['required', 'integer', 'min:10000'],
            'target_date' => ['nullable', 'date'],
            'lock_type' => ['nullable', 'in:locked,flexible'],
        ]);

        $goal = SavingsGoal::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'category' => $data['category'] ?? 'tuition',
            'target_minor' => $data['target_amount_minor'],
            'target_date' => $data['target_date'] ?? null,
            'lock_type' => $data['lock_type'] ?? 'flexible',
            'currency' => 'NGN',
        ]);

        return response()->json(['savings_goal' => $this->workspace->savingsGoal($goal)], 201);
    }

    /**
     * Move money from the wallet into a goal. It is a real debit on the ledger,
     * so an over-funded goal cannot be produced out of thin air.
     */
    public function fund(Request $request, SavingsGoal $goal): JsonResponse
    {
        abort_unless((int) $goal->user_id === (int) $request->user()->id, 403);

        $data = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:100'],
        ]);

        $wallet = $this->wallets->forUser($request->user());

        abort_if(
            $wallet['available_minor'] < $data['amount_minor'],
            422,
            'Your wallet does not have enough available balance for this contribution.'
        );

        DB::transaction(function () use ($request, $goal, $data) {
            LedgerEntry::create([
                'user_id' => $request->user()->id,
                'entry_type' => 'debit',
                'amount_minor' => $data['amount_minor'],
                'currency' => $goal->currency,
                'source_type' => 'savings_goal',
                'source_id' => (string) $goal->id,
                'idempotency_key' => 'savings_'.$goal->id.'_'.bin2hex(random_bytes(6)),
                'meta' => ['goal' => $goal->title],
                'occurred_at' => now(),
            ]);

            $goal->increment('saved_minor', $data['amount_minor']);

            if ($goal->saved_minor >= $goal->target_minor) {
                $goal->update(['status' => 'completed']);
            }
        });

        return response()->json([
            'savings_goal' => $this->workspace->savingsGoal($goal->fresh()),
            'wallet' => $this->wallets->forUser($request->user()),
        ]);
    }
}
