<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LedgerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $entries = LedgerEntry::query()
            ->visibleTo($request->user())
            ->when(
                $request->filled('entry_type'),
                fn ($query) => $query->where('entry_type', $request->string('entry_type'))
            )
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(50);

        return response()->json($entries);
    }
}
