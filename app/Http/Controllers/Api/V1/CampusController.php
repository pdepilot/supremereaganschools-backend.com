<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreCampusRequest;
use App\Http\Requests\Academic\UpdateCampusRequest;
use App\Http\Resources\Academic\CampusResource;
use App\Models\Campus;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class CampusController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Campus::class);

        return ApiResponse::success('Campuses retrieved.', CampusResource::collection(Campus::query()->orderBy('name')->get())->resolve());
    }

    public function store(StoreCampusRequest $request): JsonResponse
    {
        $campus = Campus::query()->create($request->validated());

        return ApiResponse::success('Campus created.', (new CampusResource($campus))->resolve(), 201);
    }

    public function update(UpdateCampusRequest $request, Campus $campus): JsonResponse
    {
        $campus->update($request->validated());

        return ApiResponse::success('Campus updated.', (new CampusResource($campus))->resolve());
    }

    public function destroy(Campus $campus): JsonResponse
    {
        $this->authorize('delete', $campus);

        if ($campus->classSectionOfferings()->exists()) {
            throw ValidationException::withMessages([
                'campus' => 'This campus cannot be deleted because class offerings exist.',
            ]);
        }

        if ($campus->staffAttendanceRecords()->exists() || $campus->clockOutStaffAttendanceRecords()->exists()) {
            throw ValidationException::withMessages([
                'campus' => 'This campus cannot be deleted because staff attendance records are associated with it. Please deactivate the campus instead.',
            ]);
        }

        if ($campus->staffCampusAssignments()->exists()) {
            throw ValidationException::withMessages([
                'campus' => 'This campus cannot be deleted because staff assignments exist. Please deactivate the campus instead.',
            ]);
        }

        $campus->delete();

        return ApiResponse::success('Campus deleted.');
    }
}
