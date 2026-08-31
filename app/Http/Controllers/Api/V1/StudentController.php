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

        if ($search = $request->string('q')->toString()) {
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

        if ($request->filled('level_id')) {
            $query->whereHas('enrollments.classSectionOffering.classSection.schoolClass', function ($class) use ($request) {
                $class->where('level_id', $request->integer('level_id'));
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

        $limit = $request->integer('limit');
        if ($limit > 0) {
            $query->limit(min($limit, 50));
        }

        $students = $query->orderBy('surname')->orderBy('first_name')->get();

        return ApiResponse::success('Pupils retrieved.', StudentResource::collection($students)->resolve());
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

    public function destroy(StudentProfile $studentProfile): JsonResponse
    {
        $this->authorize('delete', $studentProfile);
        $this->students->delete($studentProfile);

        return ApiResponse::success('Pupil removed.');
    }
}
