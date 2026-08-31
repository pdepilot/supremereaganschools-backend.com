<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RoleSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\UpdateSchoolSettingRequest;
use App\Http\Resources\Academic\SchoolSettingResource;
use App\Http\Resources\UserResource;
use App\Models\SchoolSetting;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\SchoolSettingService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SchoolSettingController extends Controller
{
    public function __construct(private readonly SchoolSettingService $settings) {}

    public function show(): JsonResponse
    {
        $this->authorize('viewAny', SchoolSetting::class);

        $record = SchoolSetting::query()->with(['currentAcademicSession.terms', 'currentTerm'])->first();

        if ($record === null) {
            return ApiResponse::error('School settings have not been created.', status: 404);
        }

        return ApiResponse::success('School settings retrieved.', (new SchoolSettingResource($record))->resolve());
    }

    public function update(UpdateSchoolSettingRequest $request): JsonResponse
    {
        $record = SchoolSetting::current();
        $this->authorize('update', $record);

        $record = $this->settings->update(
            $record,
            $request->validated(),
            $request->user()?->id,
        );

        return ApiResponse::success('School settings updated.', (new SchoolSettingResource($record))->resolve());
    }

    public function desks(): JsonResponse
    {
        $this->authorize('viewAny', SchoolSetting::class);

        $admins = User::query()
            ->with('roles')
            ->whereHas('roles', fn ($query) => $query->whereIn('slug', [
                RoleSlug::SuperAdmin->value,
                RoleSlug::SchoolAdmin->value,
            ]))
            ->orderBy('name')
            ->get();

        return ApiResponse::success('Desk access retrieved.', [
            'admins' => UserResource::collection($admins)->resolve(),
            'staff_count' => StaffProfile::query()->count(),
        ]);
    }
}
