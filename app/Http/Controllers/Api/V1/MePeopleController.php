<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\People\EnrollmentResource;
use App\Http\Resources\People\StudentResource;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Services\PeopleAccessService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MePeopleController extends Controller
{
    public function __construct(private readonly PeopleAccessService $access) {}

    public function children(Request $request): JsonResponse
    {
        abort_unless($this->access->isParent($request->user()) || $this->access->administers($request->user()), 403);

        $ids = $this->access->linkedStudentIds($request->user());
        $students = StudentProfile::query()
            ->with([
                'enrollments' => fn ($query) => $query->where('status', EnrollmentStatus::Active)
                    ->with(['academicSession', 'classSectionOffering.classSection']),
            ])
            ->whereIn('id', $ids)
            ->orderBy('surname')
            ->get();

        return ApiResponse::success('Children retrieved.', StudentResource::collection($students)->resolve());
    }

    public function enrollments(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($this->access->isStudent($user) || $this->access->administers($user), 403);

        $enrollments = Enrollment::query()
            ->with(['academicSession', 'classSectionOffering.classSection.schoolClass.level'])
            ->where('student_profile_id', $user->studentProfile?->id)
            ->orderByDesc('enrolled_on')
            ->get();

        return ApiResponse::success('Enrollments retrieved.', EnrollmentResource::collection($enrollments)->resolve());
    }

    public function students(Request $request): JsonResponse
    {
        abort_unless($this->access->isTeacher($request->user()) || $this->access->administers($request->user()), 403);

        $ids = $this->access->assignedStudentIds($request->user());
        $students = StudentProfile::query()
            ->with([
                'enrollments' => fn ($query) => $query->where('status', EnrollmentStatus::Active)
                    ->with('classSectionOffering.classSection'),
            ])
            ->whereIn('id', $ids)
            ->orderBy('surname')
            ->get();

        return ApiResponse::success('Assigned pupils retrieved.', StudentResource::collection($students)->resolve());
    }
}
