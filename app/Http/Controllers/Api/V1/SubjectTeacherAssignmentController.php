<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\StoreSubjectTeacherAssignmentRequest;
use App\Http\Resources\People\SubjectTeacherAssignmentResource;
use App\Models\SubjectTeacherAssignment;
use App\Services\SubjectTeacherAssignmentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectTeacherAssignmentController extends Controller
{
    public function __construct(private readonly SubjectTeacherAssignmentService $assignments) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SubjectTeacherAssignment::class);

        $query = SubjectTeacherAssignment::query()->with([
            'staff.user',
            'subjectOffering.subject',
            'subjectOffering.classSectionOffering.classSection',
        ]);
        $user = $request->user();

        if (! $user?->hasAnyRole(\App\Enums\RoleSlug::SuperAdmin, \App\Enums\RoleSlug::SchoolAdmin)) {
            $query->where('staff_profile_id', $user?->staffProfile?->id);
        }

        if ($request->filled('subject_offering_id')) {
            $query->where('subject_offering_id', $request->integer('subject_offering_id'));
        }

        return ApiResponse::success(
            'Subject teacher assignments retrieved.',
            SubjectTeacherAssignmentResource::collection($query->orderByDesc('assigned_on')->get())->resolve(),
        );
    }

    public function store(StoreSubjectTeacherAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignments->assign($request->validated(), $request->user()?->id);

        return ApiResponse::success(
            'Subject teacher assigned.',
            (new SubjectTeacherAssignmentResource($assignment))->resolve(),
            201,
        );
    }

    public function destroy(SubjectTeacherAssignment $subjectTeacherAssignment): JsonResponse
    {
        $this->authorize('delete', $subjectTeacherAssignment);
        $assignment = $this->assignments->end($subjectTeacherAssignment);

        return ApiResponse::success('Subject teacher assignment ended.', (new SubjectTeacherAssignmentResource($assignment))->resolve());
    }
}
