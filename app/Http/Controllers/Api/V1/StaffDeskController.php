<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthPortal;
use App\Http\Controllers\Controller;
use App\Services\StaffDeskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffDeskController extends Controller
{
    public function __construct(private readonly StaffDeskService $desk) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null && $user->hasAnyRole(...AuthPortal::Staff->allowedRoles()),
            403,
        );

        return ApiResponse::success(
            'Faculty desk retrieved.',
            $this->desk->snapshot($user),
        );
    }
}
