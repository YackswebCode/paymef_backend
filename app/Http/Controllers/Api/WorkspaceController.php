<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Workspace\WorkspacePresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(private readonly WorkspacePresenter $workspace) {}

    /**
     * Everything the signed-in dashboard shows, in one authenticated response.
     */
    public function show(Request $request): JsonResponse
    {
        return response()
            ->json(['workspace' => $this->workspace->for($request->user())])
            ->header('Cache-Control', 'private, no-store');
    }
}
