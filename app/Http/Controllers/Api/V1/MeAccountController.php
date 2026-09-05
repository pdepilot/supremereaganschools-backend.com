<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Requests\Account\UpdatePasswordRequest;
use App\Http\Resources\UserResource;
use App\Services\AccountService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeAccountController extends Controller
{
    public function __construct(private readonly AccountService $accounts) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            'Account retrieved.',
            (new UserResource($request->user()->load('roles')))->resolve(),
        );
    }

    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $this->accounts->update($request->user(), $request->validated());

        return ApiResponse::success(
            'Account details updated.',
            (new UserResource($user))->resolve(),
        );
    }

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $user = $this->accounts->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
        );

        return ApiResponse::success(
            'Passphrase reset.',
            (new UserResource($user))->resolve(),
        );
    }
}
