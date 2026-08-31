<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\StoreStaffRequest;
use App\Http\Requests\People\UpdateStaffRequest;
use App\Http\Resources\People\StaffResource;
use App\Models\StaffProfile;
use App\Services\StaffService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(private readonly StaffService $staff) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $query = StaffProfile::query()->with($this->staff->defaultRelations());

        if (! $request->user()?->hasAnyRole(\App\Enums\RoleSlug::SuperAdmin, \App\Enums\RoleSlug::SchoolAdmin)) {
            $query->where('user_id', $request->user()?->id);
        }

        if ($search = $request->string('q')->toString()) {
            $query->where(function ($builder) use ($search) {
                $builder->where('staff_number', 'like', '%'.$search.'%')
                    ->orWhere('job_title', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', '%'.$search.'%'));
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return ApiResponse::success(
            'Staff retrieved.',
            StaffResource::collection($query->orderBy('staff_number')->get())->resolve(),
        );
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        $staff = $this->staff->create($request->validated(), $request->user()?->id);

        return ApiResponse::success('Staff created.', (new StaffResource($staff))->resolve(), 201);
    }

    public function show(StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('view', $staffProfile);

        return ApiResponse::success(
            'Staff retrieved.',
            (new StaffResource($staffProfile->load($this->staff->defaultRelations())))->resolve(),
        );
    }

    public function update(UpdateStaffRequest $request, StaffProfile $staffProfile): JsonResponse
    {
        $staff = $this->staff->update($staffProfile, $request->validated(), $request->user()?->id);

        return ApiResponse::success('Staff updated.', (new StaffResource($staff))->resolve());
    }

    public function suspend(StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('update', $staffProfile);

        return ApiResponse::success(
            'Staff suspended.',
            (new StaffResource($this->staff->suspend($staffProfile)))->resolve(),
        );
    }

    public function reinstate(StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('update', $staffProfile);

        return ApiResponse::success(
            'Staff reinstated.',
            (new StaffResource($this->staff->reinstate($staffProfile)))->resolve(),
        );
    }

    public function destroy(StaffProfile $staffProfile): JsonResponse
    {
        $this->authorize('delete', $staffProfile);
        $this->staff->delete($staffProfile);

        return ApiResponse::success('Staff removed.');
    }
}
