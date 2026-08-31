<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\StoreClassTeacherAssignmentRequest;
use App\Http\Resources\People\ClassTeacherAssignmentResource;
use App\Models\ClassTeacherAssignment;
use App\Services\ClassTeacherAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassTeacherAssignmentController extends Controller
{
    public function __construct(private readonly ClassTeacherAssignmentService $assignments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ClassTeacherAssignment::class);

        $query = ClassTeacherAssignment::query()->with(['staff.user', 'classSectionOffering.classSection']);
        $user = $request->user();

        if (! $user?->hasAnyRole(\App\Enums\RoleSlug::SuperAdmin, \App\Enums\RoleSlug::SchoolAdmin)) {
            $query->where('staff_profile_id', $user?->staffProfile?->id);
        }

        if ($request->filled('class_section_offering_id')) {
            $query->where('class_section_offering_id', $request->integer('class_section_offering_id'));
        }

        return ApiResponse::success(
            'Class teacher assignments retrieved.',
            ClassTeacherAssignmentResource::collection($query->orderByDesc('assigned_on')->get())->resolve(),
        );
    }

    public function store(StoreClassTeacherAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignments->assign($request->validated(), $request->user()?->id);

        return ApiResponse::success(
            'Class teacher assigned.',
            (new ClassTeacherAssignmentResource($assignment))->resolve(),
            201,
        );
    }

    public function destroy(ClassTeacherAssignment $classTeacherAssignment): JsonResponse
    {
        $this->authorize('delete', $classTeacherAssignment);
        $assignment = $this->assignments->end($classTeacherAssignment);

        return ApiResponse::success('Class teacher assignment ended.', (new ClassTeacherAssignmentResource($assignment))->resolve());
    }
}
