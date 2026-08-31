<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthPortal;
use App\Http\Controllers\Controller;
use App\Services\StudentDeskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentDeskController extends Controller
{
    public function __construct(private readonly StudentDeskService $desk) {}

    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            $user !== null && $user->hasAnyRole(...AuthPortal::Student->allowedRoles()),
            403,
        );

        return ApiResponse::success(
            'Pupil desk retrieved.',
            $this->desk->snapshot($user),
        );
    }
}
