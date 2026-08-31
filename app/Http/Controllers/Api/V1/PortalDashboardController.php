<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use App\Services\PortalDashboardService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalDashboardController extends Controller
{
    public function __construct(private readonly PortalDashboardService $dashboard) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolSetting::class);

        return ApiResponse::success(
            'Command desk retrieved.',
            $this->dashboard->snapshot($request->user()),
        );
    }
}
