<?php

namespace App\Services\Wallet;

use App\Models\LedgerEntry;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class WalletService
{
    /**
     * Derive a wallet summary from ledger entries.
     *
     * credit  +  money in
     * debit   -  money out
     * hold    -  money reserved and unavailable
     * release +  a previously held amount becoming available again
     * refund  -  money returned to the payer
     */
    public function forUser(User $user, string $currency = 'NGN'): array
    {
        return $this->summarize(
            LedgerEntry::query()->where('user_id', $user->id),
            $currency
        );
    }

    public function forSchool(School $school, string $currency = 'NGN'): array
    {
        return $this->summarize(
            LedgerEntry::query()->where('school_id', $school->id),
            $currency
        );
    }

    private function summarize(Builder $query, string $currency): array
    {
        $totals = [];
        $entryCount = 0;

        $rows = (clone $query)
            ->where('currency', $currency)
            ->selectRaw('entry_type, SUM(amount_minor) as total, COUNT(*) as entry_count')
            ->groupBy('entry_type')
            ->get();

        foreach ($rows as $row) {
            $totals[$row->entry_type] = (int) $row->total;
            $entryCount += (int) $row->entry_count;
        }

        $credits = $totals['credit'] ?? 0;
        $debits = $totals['debit'] ?? 0;
        $holds = $totals['hold'] ?? 0;
        $releases = $totals['release'] ?? 0;
        $refunds = $totals['refund'] ?? 0;

        return [
            'currency' => $currency,
            'available_minor' => $credits + $releases - $debits - $holds - $refunds,
            'totals' => [
                'credits_minor' => $credits,
                'debits_minor' => $debits,
                'holds_minor' => $holds,
                'releases_minor' => $releases,
                'refunds_minor' => $refunds,
            ],
            'entry_count' => $entryCount,
        ];
    }
}
