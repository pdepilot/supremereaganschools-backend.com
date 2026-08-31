<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\StoreEnrollmentRequest;
use App\Http\Requests\People\UpdateEnrollmentRequest;
use App\Http\Resources\People\EnrollmentResource;
use App\Models\Enrollment;
use App\Services\EnrollmentService;
use App\Services\PeopleAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly PeopleAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Enrollment::class);

        $query = Enrollment::query()->with([
            'student',
            'academicSession',
            'classSectionOffering.classSection.schoolClass.level',
        ]);

        $user = $request->user();

        if ($this->access->isStudent($user) && ! $this->access->administers($user)) {
            $query->where('student_profile_id', $user->studentProfile?->id);
        } elseif ($this->access->isParent($user) && ! $this->access->administers($user)) {
            $query->whereIn('student_profile_id', $this->access->linkedStudentIds($user));
        } elseif ($this->access->isTeacher($user) && ! $this->access->administers($user)) {
            $query->whereIn('class_section_offering_id', $this->access->assignedOfferingIds($user));
        }

        if ($request->filled('student_profile_id')) {
            $query->where('student_profile_id', $request->integer('student_profile_id'));
        }

        if ($request->filled('academic_session_id')) {
            $query->where('academic_session_id', $request->integer('academic_session_id'));
        }

        return ApiResponse::success(
            'Enrollments retrieved.',
            EnrollmentResource::collection($query->orderByDesc('enrolled_on')->get())->resolve(),
        );
    }

    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $enrollment = $this->enrollments->create($request->validated(), $request->user()?->id);

        return ApiResponse::success('Enrollment created.', (new EnrollmentResource($enrollment))->resolve(), 201);
    }

    public function show(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('view', $enrollment);

        return ApiResponse::success(
            'Enrollment retrieved.',
            (new EnrollmentResource($enrollment->load([
                'student',
                'academicSession',
                'classSectionOffering.classSection.schoolClass.level',
            ])))->resolve(),
        );
    }

    public function update(UpdateEnrollmentRequest $request, Enrollment $enrollment): JsonResponse
    {
        $enrollment = $this->enrollments->update($enrollment, $request->validated());

        return ApiResponse::success('Enrollment updated.', (new EnrollmentResource($enrollment))->resolve());
    }

    public function destroy(Enrollment $enrollment): JsonResponse
    {
        $this->authorize('delete', $enrollment);

        $enrollment->update([
            'status' => \App\Enums\EnrollmentStatus::Withdrawn,
            'left_on' => $enrollment->left_on?->toDateString() ?? now()->toDateString(),
        ]);

        return ApiResponse::success(
            'Enrollment withdrawn.',
            (new EnrollmentResource($enrollment->fresh([
                'student',
                'academicSession',
                'classSectionOffering.classSection.schoolClass.level',
            ])))->resolve(),
        );
    }
}
