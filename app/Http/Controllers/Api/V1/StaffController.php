<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionSlug;
use App\Enums\StaffStatus;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\StoreStaffRequest;
use App\Http\Requests\People\UpdateStaffRequest;
use App\Http\Resources\People\StaffResource;
use App\Models\StaffProfile;
use App\Services\PeopleAccessService;
use App\Services\StaffService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffService $staff,
        private readonly PeopleAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StaffProfile::class);

        $canViewDirectory = $this->access->allows(
            $request->user(),
            PermissionSlug::StaffView,
            PermissionSlug::StaffEdit,
            PermissionSlug::AttendanceManage,
        );

        $query = StaffProfile::query()->with($this->staff->defaultRelations());

        if (! $canViewDirectory) {
            $query->where('user_id', $request->user()?->id);
        }

        if ($search = $request->string('q')->toString()) {
            $query->where(function ($builder) use ($search) {
                $term = '%'.$search.'%';
                $builder->where('staff_number', 'like', $term)
                    ->orWhere('job_title', 'like', $term)
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', $term))
                    ->orWhereHas('department', fn ($dept) => $dept->where('name', 'like', $term))
                    ->orWhereHas('subjectTeacherAssignments', function ($sub) use ($term) {
                        $sub->where('is_active', true)
                            ->whereHas('subjectOffering.subject', fn ($subject) => $subject->where('name', 'like', $term));
                    })
                    ->orWhereHas('classTeacherAssignments', function ($cla) use ($term) {
                        $cla->where('is_active', true)
                            ->whereHas('classSectionOffering.classSection', fn ($sec) => $sec->where('name', 'like', $term));
                    });
            });
        }

        if ($request->filled('department_id')) {
            $query->where('department_id', $request->integer('department_id'));
        }

        if ($request->filled('form')) {
            $form = $request->string('form')->toString();
            $query->whereHas('classTeacherAssignments', function ($assignment) use ($form) {
                $assignment->where('is_active', true)
                    ->whereHas('classSectionOffering.classSection', fn ($section) => $section->where('name', $form));
            });
        }

        if ($request->filled('status')) {
            $status = $request->string('status')->toString();
            if ($status === 'suspended') {
                $query->whereHas('user', fn ($user) => $user->where('status', UserStatus::Suspended->value));
            } else {
                $query->where('status', $status);
            }
        }

        $supportedPerPage = [5, 10, 25, 50, 100];
        $requestedPerPage = $request->integer('per_page', 5);
        if (! in_array($requestedPerPage, $supportedPerPage, true)) {
            if ($requestedPerPage <= 5) {
                $perPage = 5;
            } elseif ($requestedPerPage >= 100) {
                $perPage = 100;
            } elseif ($requestedPerPage > 5 && $requestedPerPage <= 15) {
                $perPage = 10;
            } elseif ($requestedPerPage > 15 && $requestedPerPage <= 37) {
                $perPage = 25;
            } elseif ($requestedPerPage > 37 && $requestedPerPage < 100) {
                $perPage = 50;
            } else {
                $perPage = 5;
            }
        } else {
            $perPage = $requestedPerPage;
        }

        $rows = $query->orderBy('staff_number')->paginate($perPage);

        $summaryQuery = StaffProfile::query();
        if (! $canViewDirectory) {
            $summaryQuery->where('user_id', $request->user()?->id);
        }

        $totalStaff = (clone $summaryQuery)->count();
        $activeStaff = (clone $summaryQuery)
            ->where('status', StaffStatus::Active->value)
            ->whereHas('user', fn ($user) => $user->where('status', '!=', UserStatus::Suspended->value))
            ->count();

        return ApiResponse::success('Staff retrieved.', [
            'items' => StaffResource::collection($rows)->resolve(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
                'total' => $rows->total(),
            ],
            'summary' => [
                'total' => $totalStaff,
                'active' => $activeStaff,
            ],
        ]);
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
