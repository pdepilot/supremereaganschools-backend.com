<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnrollmentStatus;
use App\Enums\SchoolWing;
use App\Http\Controllers\Controller;
use App\Http\Requests\People\StoreStudentRequest;
use App\Http\Requests\People\UpdateStudentRequest;
use App\Http\Resources\People\StudentResource;
use App\Models\StudentProfile;
use App\Services\PeopleAccessService;
use App\Services\StudentService;
use App\Support\ApiResponse;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    public function __construct(
        private readonly StudentService $students,
        private readonly PeopleAccessService $access,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', StudentProfile::class);

        $query = StudentProfile::query()
            ->with([
                'enrollments' => fn ($enrollment) => $enrollment
                    ->where('status', EnrollmentStatus::Active)
                    ->with([
                        'academicSession',
                        'classSectionOffering.campus',
                        'classSectionOffering.classSection.schoolClass.level',
                    ]),
                'guardians',
                'user',
            ]);

        $user = $request->user();

        if ($this->access->administers($user)) {
            $query->with('invoices');
        } elseif ($this->access->isTeacher($user)) {
            $query->whereIn('id', $this->access->assignedStudentIds($user));
        } elseif ($this->access->isParent($user)) {
            $query->whereIn('id', $this->access->linkedStudentIds($user));
        } else {
            $query->where('id', $user?->studentProfile?->id);
        }

        $search = $request->filled('q') ? $request->string('q')->toString() : $request->string('search')->toString();
        if ($search !== '') {
            $query->where(function ($builder) use ($search) {
                $builder->where('admission_number', 'like', '%'.$search.'%')
                    ->orWhere('surname', 'like', '%'.$search.'%')
                    ->orWhere('first_name', 'like', '%'.$search.'%')
                    ->orWhere('other_names', 'like', '%'.$search.'%');
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('class_id') || $request->filled('school_class_id')) {
            $classId = (int) ($request->input('class_id') ?: $request->input('school_class_id'));
            $query->whereHas('enrollments', function ($enrollment) use ($classId) {
                $enrollment->where('status', EnrollmentStatus::Active)
                    ->whereHas(
                        'classSectionOffering.classSection',
                        fn ($section) => $section->where('school_class_id', $classId)
                    );
            });
        }

        if ($request->filled('class_section_id')) {
            $sectionId = $request->integer('class_section_id');
            $query->whereHas('enrollments', function ($enrollment) use ($sectionId) {
                $enrollment->where('status', EnrollmentStatus::Active)
                    ->whereHas(
                        'classSectionOffering',
                        fn ($offering) => $offering->where('class_section_id', $sectionId)
                    );
            });
        }

        if ($request->filled('class_section_offering_id')) {
            $offeringId = $request->integer('class_section_offering_id');
            $query->whereHas('enrollments', function ($enrollment) use ($offeringId) {
                $enrollment->where('status', EnrollmentStatus::Active)
                    ->where('class_section_offering_id', $offeringId);
            });
        }

        if ($request->filled('campus_id')) {
            $campusId = $request->integer('campus_id');
            $query->whereHas('enrollments', function ($enrollment) use ($campusId) {
                $enrollment->where('status', EnrollmentStatus::Active)
                    ->whereHas(
                        'classSectionOffering',
                        fn ($offering) => $offering->where('campus_id', $campusId)
                    );
            });
        }

        if ($request->filled('academic_session_id')) {
            $sessionId = $request->integer('academic_session_id');
            $query->whereHas('enrollments', function ($enrollment) use ($sessionId) {
                $enrollment->where('status', EnrollmentStatus::Active)
                    ->where('academic_session_id', $sessionId);
            });
        }

        if ($request->filled('level_id')) {
            $levelId = $request->integer('level_id');
            $query->whereHas('enrollments', function ($enrollment) use ($levelId) {
                $enrollment->where('status', EnrollmentStatus::Active)
                    ->whereHas(
                        'classSectionOffering.classSection.schoolClass',
                        fn ($class) => $class->where('level_id', $levelId)
                    );
            });
        }

        if ($request->filled('wing')) {
            $wing = SchoolWing::tryFrom($request->string('wing')->toString());
            if ($wing === null) {
                throw ValidationException::withMessages([
                    'wing' => 'The wing must be nursery, primary, or secondary.',
                ]);
            }

            $slugs = $wing->levelSlugs();
            $query->whereHas('enrollments', function ($enrollment) use ($slugs) {
                $enrollment->where('status', EnrollmentStatus::Active)
                    ->whereHas(
                        'classSectionOffering.classSection.schoolClass.level',
                        fn ($level) => $level->whereIn('slug', $slugs),
                    );
            });
        }

        $query->orderBy('surname')->orderBy('first_name');

        $limit = $request->integer('limit');
        if ($limit > 0) {
            $students = $query->limit(min($limit, 50))->get();

            return ApiResponse::success('Pupils retrieved.', StudentResource::collection($students)->resolve());
        }

        if ($request->boolean('all') || $request->input('paginate') === '0') {
            $students = $query->get();

            return ApiResponse::success('Pupils retrieved.', StudentResource::collection($students)->resolve());
        }

        $perPage = max(1, min(100, $request->integer('per_page', 10)));
        $rows = $query->paginate($perPage);

        return ApiResponse::paginated(
            'Pupils retrieved.',
            StudentResource::collection($rows->items())->resolve(),
            $rows
        );
    }

    public function store(StoreStudentRequest $request): JsonResponse
    {
        $student = $this->students->create($request->validated(), $request->user()?->id);

        return ApiResponse::success('Pupil created.', (new StudentResource($student))->resolve(), 201);
    }

    public function show(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->authorize('view', $studentProfile);

        $relations = [
            'enrollments.classSectionOffering.classSection.schoolClass.level',
            'enrollments.classSectionOffering.campus',
            'enrollments.academicSession',
            'guardians',
            'user',
        ];

        if ($this->access->administers($request->user())) {
            $relations[] = 'invoices';
        }

        return ApiResponse::success(
            'Pupil retrieved.',
            (new StudentResource($studentProfile->load($relations)))->resolve(),
        );
    }

    public function photo(StudentProfile $studentProfile): StreamedResponse
    {
        $this->authorize('view', $studentProfile);

        $disk = Storage::disk('local');
        assert($disk instanceof FilesystemAdapter);

        if (! $studentProfile->photo_path || ! $disk->exists($studentProfile->photo_path)) {
            abort(404);
        }

        return $disk->response($studentProfile->photo_path, null, [
            'Cache-Control' => 'private, max-age=3600',
        ]);
    }

    public function update(UpdateStudentRequest $request, StudentProfile $studentProfile): JsonResponse
    {
        $student = $this->students->update($studentProfile, $request->validated());

        return ApiResponse::success('Pupil updated.', (new StudentResource($student))->resolve());
    }

    public function suspend(StudentProfile $studentProfile): JsonResponse
    {
        $this->authorize('update', $studentProfile);

        return ApiResponse::success(
            'Pupil suspended.',
            (new StudentResource($this->students->suspend($studentProfile)))->resolve(),
        );
    }

    public function reinstate(StudentProfile $studentProfile): JsonResponse
    {
        $this->authorize('update', $studentProfile);

        return ApiResponse::success(
            'Pupil reinstated.',
            (new StudentResource($this->students->reinstate($studentProfile)))->resolve(),
        );
    }

    public function destroy(Request $request, StudentProfile $studentProfile): JsonResponse
    {
        $this->authorize('delete', $studentProfile);
        abort_unless($request->user() instanceof \App\Models\User, 403);
        $this->students->delete($studentProfile, $request->user());

        return ApiResponse::success('Pupil removed. Contact details were cleared for reuse and sealed in the audit log.');
    }
}
