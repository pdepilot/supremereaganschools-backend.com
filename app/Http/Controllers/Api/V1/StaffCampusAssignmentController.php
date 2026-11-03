<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionSlug;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AssignStaffCampusRequest;
use App\Http\Resources\Attendance\StaffCampusAssignmentResource;
use App\Models\StaffCampusAssignment;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffCampusAssignmentController extends Controller
{
    protected function authorizeManage(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            $user->hasAnyPermission(PermissionSlug::StaffEdit, PermissionSlug::AttendanceManage),
            403,
            'Unauthorized to manage staff campus assignments.'
        );
    }

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(
            $user->hasAnyPermission(PermissionSlug::StaffView, PermissionSlug::AttendanceView, PermissionSlug::AttendanceManage),
            403,
            'Unauthorized.'
        );

        $query = StaffCampusAssignment::query()
            ->with(['staffProfile.user', 'campus', 'assigner'])
            ->latest('start_date');

        if ($request->filled('staff_profile_id')) {
            $query->where('staff_profile_id', $request->query('staff_profile_id'));
        }

        if ($request->filled('campus_id')) {
            $query->where('campus_id', $request->query('campus_id'));
        }

        if ($request->has('is_active') && $request->query('is_active') !== '') {
            $query->where('is_active', (bool) $request->query('is_active'));
        }

        $perPage = (int) $request->query('per_page', 25);
        $paginator = $query->paginate($perPage);

        return ApiResponse::paginated(
            'Staff campus assignments retrieved.',
            StaffCampusAssignmentResource::collection($paginator->items())->resolve(),
            $paginator
        );
    }

    public function store(AssignStaffCampusRequest $request): JsonResponse
    {
        $this->authorizeManage($request);

        $staffProfileId = (int) $request->validated('staff_profile_id');
        $campusId = (int) $request->validated('campus_id');
        $isActive = (bool) $request->validated('is_active', true);

        if ($isActive) {
            $existingActive = StaffCampusAssignment::query()
                ->where('staff_profile_id', $staffProfileId)
                ->where('campus_id', $campusId)
                ->where('is_active', true)
                ->exists();

            if ($existingActive) {
                throw ValidationException::withMessages([
                    'campus_id' => 'This staff member is already actively assigned to this campus. Please update or end the existing assignment instead.',
                ]);
            }
        }

        $assignment = DB::transaction(function () use ($request, $staffProfileId, $campusId, $isActive) {
            $isPrimary = (bool) $request->validated('is_primary', false);

            if ($isPrimary) {
                StaffCampusAssignment::query()
                    ->where('staff_profile_id', $staffProfileId)
                    ->update(['is_primary' => false]);
            }

            return StaffCampusAssignment::query()->create([
                'staff_profile_id' => $staffProfileId,
                'campus_id' => $campusId,
                'is_primary' => $isPrimary,
                'start_date' => $request->validated('start_date'),
                'end_date' => $request->validated('end_date'),
                'is_active' => $isActive,
                'assigned_by' => $request->user()?->id,
                'notes' => $request->validated('notes'),
            ]);
        });

        return ApiResponse::success(
            'Staff assigned to campus successfully.',
            (new StaffCampusAssignmentResource($assignment->load(['staffProfile.user', 'campus', 'assigner'])))->resolve(),
            201
        );
    }

    public function update(Request $request, StaffCampusAssignment $assignment): JsonResponse
    {
        $this->authorizeManage($request);

        $validated = $request->validate([
            'is_primary' => ['sometimes', 'boolean'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $newStartDate = $validated['start_date'] ?? $assignment->start_date?->toDateString();
        $newEndDate = $validated['end_date'] ?? $assignment->end_date?->toDateString();

        if (
            isset($validated['end_date'])
            && $validated['end_date'] !== null
            && $newStartDate !== null
            && $validated['end_date'] < $newStartDate
        ) {
            throw ValidationException::withMessages([
                'end_date' => 'The end date must be on or after the assignment start date.',
            ]);
        }

        $deactivating = $assignment->is_active
            && array_key_exists('is_active', $validated)
            && ! $validated['is_active'];

        $reactivating = ! $assignment->is_active
            && ! empty($validated['is_active']);

        if ($reactivating && $assignment->end_date !== null) {
            throw ValidationException::withMessages([
                'is_active' => 'An ended assignment cannot be reactivated. Create a new campus assignment instead.',
            ]);
        }

        if ($reactivating) {
            $duplicateActive = StaffCampusAssignment::query()
                ->where('staff_profile_id', $assignment->staff_profile_id)
                ->where('campus_id', $assignment->campus_id)
                ->where('id', '!=', $assignment->id)
                ->where('is_active', true)
                ->exists();

            if ($duplicateActive) {
                throw ValidationException::withMessages([
                    'is_active' => 'This staff member already has an active assignment to this campus.',
                ]);
            }
        }

        if (
            array_key_exists('is_primary', $validated)
            && $validated['is_primary']
            && array_key_exists('is_active', $validated)
            && ! $validated['is_active']
        ) {
            throw ValidationException::withMessages([
                'is_primary' => 'An inactive campus assignment cannot be primary.',
            ]);
        }

        DB::transaction(function () use ($assignment, &$validated, $deactivating) {
            if ($deactivating) {
                $validated['end_date'] = $validated['end_date'] ?? now()->toDateString();
                $validated['is_primary'] = false;
            }

            if (! empty($validated['is_primary'])) {
                StaffCampusAssignment::query()
                    ->where('staff_profile_id', $assignment->staff_profile_id)
                    ->where('id', '!=', $assignment->id)
                    ->update(['is_primary' => false]);
            }

            $assignment->update($validated);
        });

        return ApiResponse::success(
            'Staff campus assignment updated.',
            (new StaffCampusAssignmentResource(
                $assignment->fresh(['staffProfile.user', 'campus', 'assigner'])
            ))->resolve()
        );
    }

    public function destroy(Request $request, StaffCampusAssignment $assignment): JsonResponse
    {
        $this->authorizeManage($request);

        DB::transaction(function () use ($assignment) {
            if ($assignment->is_active || $assignment->end_date === null) {
                $assignment->update([
                    'is_active' => false,
                    'is_primary' => false,
                    'end_date' => $assignment->end_date ?? now()->toDateString(),
                ]);
            }
        });

        return ApiResponse::success(
            'Staff campus assignment ended successfully.'
        );
    }
}
