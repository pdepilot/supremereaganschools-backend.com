<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthPortal;
use App\Http\Controllers\Controller;
use App\Services\ParentDeskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ParentDeskController extends Controller
{
    public function __construct(private readonly ParentDeskService $desk) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null && $user->hasAnyRole(...AuthPortal::Parent->allowedRoles()),
            403,
        );

        return ApiResponse::success(
            'Family desk retrieved.',
            $this->desk->snapshot($user),
        );
    }
}
