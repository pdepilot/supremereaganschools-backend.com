<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\BulkAttendanceRequest;
use App\Http\Requests\Attendance\StoreAttendanceRequest;
use App\Http\Requests\Attendance\UpdateAttendanceRequest;
use App\Http\Resources\Attendance\AttendanceRecordResource;
use App\Models\AttendanceRecord;
use App\Models\ClassSectionOffering;
use App\Services\AttendanceService;
use App\Services\PeopleAccessService;
use App\Support\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly PeopleAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $query = AttendanceRecord::query()->with($this->attendance->defaultRelations());
        $user = $request->user();

        if ($this->access->isStudent($user) && ! $this->access->administers($user)) {
            $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('student_profile_id', $user->studentProfile?->id));
        } elseif ($this->access->isParent($user) && ! $this->access->administers($user)) {
            $ids = $this->access->linkedStudentIds($user);
            if ($request->filled('student_profile_id')) {
                $this->assertLinkedStudent($request->integer('student_profile_id'), $ids);
                $ids = collect([$request->integer('student_profile_id')]);
            }
            $query->whereHas('enrollment', fn ($enrollment) => $enrollment->whereIn('student_profile_id', $ids));
        } elseif ($this->access->isTeacher($user) && ! $this->access->administers($user)) {
            $offeringIds = $this->access->assignedOfferingIds($user);
            if ($request->filled('class_section_offering_id')) {
                $this->assertViewableOffering($user, $request->integer('class_section_offering_id'));
                $query->where('class_section_offering_id', $request->integer('class_section_offering_id'));
            } else {
                $query->whereIn('class_section_offering_id', $offeringIds);
            }
        } elseif ($request->filled('class_section_offering_id')) {
            $query->where('class_section_offering_id', $request->integer('class_section_offering_id'));
        }

        if ($request->filled('student_profile_id') && ($this->access->administers($user) || $this->access->isTeacher($user))) {
            if ($this->access->isTeacher($user) && ! $this->access->administers($user)) {
                $student = \App\Models\StudentProfile::query()->findOrFail($request->integer('student_profile_id'));
                abort_unless($this->access->canViewStudent($user, $student), 403);
            }
            $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('student_profile_id', $request->integer('student_profile_id')));
        }

        if ($request->filled('enrollment_id')) {
            $query->where('enrollment_id', $request->integer('enrollment_id'));
        }

        if ($request->filled('marked_on')) {
            $query->whereDate('marked_on', $request->string('marked_on'));
        }

        if ($request->filled('from')) {
            $query->whereDate('marked_on', '>=', $request->string('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('marked_on', '<=', $request->string('to'));
        }

        if ($request->filled('term_id')) {
            $term = \App\Models\Term::query()->find($request->integer('term_id'));
            abort_unless($term !== null, 404);
            if ($term->starts_on && $term->ends_on) {
                $query->whereDate('marked_on', '>=', $term->starts_on->toDateString())
                    ->whereDate('marked_on', '<=', $term->ends_on->toDateString());
            } else {
                $query->whereHas('enrollment', fn ($enrollment) => $enrollment->where('academic_session_id', $term->academic_session_id));
            }
        }

        $records = $query->orderByDesc('marked_on')->orderBy('id')->get();

        return ApiResponse::success('Attendance retrieved.', AttendanceRecordResource::collection($records)->resolve());
    }

    public function register(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $validated = $request->validate([
            'class_section_offering_id' => ['required', 'integer', 'exists:class_section_offerings,id'],
            'marked_on' => ['required', 'date'],
        ]);

        $payload = $this->attendance->register(
            (int) $validated['class_section_offering_id'],
            $validated['marked_on'],
            $request->user(),
        );

        $payload['students'] = collect($payload['students'])->map(function (array $row) {
            $row['attendance'] = $row['attendance']
                ? (new AttendanceRecordResource($row['attendance']))->resolve()
                : null;

            return $row;
        })->all();

        return ApiResponse::success('Attendance register retrieved.', $payload);
    }

    public function offerings(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $user = $request->user();
        $ids = $this->access->administers($user)
            ? ClassSectionOffering::query()->pluck('id')
            : $this->access->assignedOfferingIds($user);

        $offerings = ClassSectionOffering::query()
            ->with(['classSection', 'academicSession'])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn (ClassSectionOffering $offering) => [
                'id' => $offering->id,
                'form' => $offering->classSection?->name,
                'session_name' => $offering->academicSession?->name,
                'can_mark' => $this->access->canMarkAttendanceForOffering($user, $offering->id),
            ])->values()->all();

        return ApiResponse::success('Attendance classes retrieved.', $offerings);
    }

    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AttendanceRecord::class);

        $user = $request->user();

        if ($request->filled('class_section_offering_id') && $request->filled('marked_on')) {
            $this->assertViewableOffering($user, $request->integer('class_section_offering_id'));

            return ApiResponse::success(
                'Class attendance summary retrieved.',
                $this->attendance->classSummary(
                    $request->integer('class_section_offering_id'),
                    (string) $request->string('marked_on'),
                    $user,
                ),
            );
        }

        $studentId = $request->integer('student_profile_id') ?: null;
        if ($this->access->isStudent($user) && ! $this->access->administers($user)) {
            $studentId = $user->studentProfile?->id;
        }

        $payload = $this->attendance->studentSummary(
            $studentId,
            $request->integer('enrollment_id') ?: null,
            $request->input('from'),
            $request->input('to'),
            $request->integer('term_id') ?: null,
            $user,
        );

        $payload['records'] = AttendanceRecordResource::collection($payload['records'])->resolve();

        return ApiResponse::success('Attendance summary retrieved.', $payload);
    }

    public function store(StoreAttendanceRequest $request): JsonResponse
    {
        $record = $this->attendance->mark($request->validated(), $request->user());

        return ApiResponse::success('Attendance recorded.', (new AttendanceRecordResource($record))->resolve(), 201);
    }

    public function bulk(BulkAttendanceRequest $request): JsonResponse
    {
        $records = $this->attendance->bulk($request->validated(), $request->user());

        return ApiResponse::success(
            'Attendance recorded.',
            AttendanceRecordResource::collection($records)->resolve(),
        );
    }

    public function show(AttendanceRecord $attendanceRecord): JsonResponse
    {
        $this->authorize('view', $attendanceRecord);

        return ApiResponse::success(
            'Attendance retrieved.',
            (new AttendanceRecordResource($attendanceRecord->load($this->attendance->defaultRelations())))->resolve(),
        );
    }

    public function update(UpdateAttendanceRequest $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        $record = $this->attendance->update($attendanceRecord, $request->validated(), $request->user());

        return ApiResponse::success('Attendance updated.', (new AttendanceRecordResource($record))->resolve());
    }

    public function destroy(Request $request, AttendanceRecord $attendanceRecord): JsonResponse
    {
        $this->authorize('delete', $attendanceRecord);
        $this->attendance->delete($attendanceRecord, $request->user());

        return ApiResponse::success('Attendance removed.');
    }

    private function assertLinkedStudent(int $studentId, $ids): void
    {
        if (! $ids->contains($studentId)) {
            throw new AuthorizationException;
        }
    }

    private function assertViewableOffering($user, int $offeringId): void
    {
        if (! $this->access->canViewAttendanceForOffering($user, $offeringId)) {
            throw new AuthorizationException;
        }
    }
}
