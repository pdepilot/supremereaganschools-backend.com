<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreClassSectionOfferingRequest;
use App\Http\Requests\Academic\UpdateClassSectionOfferingRequest;
use App\Http\Resources\Academic\ClassSectionOfferingResource;
use App\Models\ClassSectionOffering;
use App\Models\StudentProfile;
use App\Models\SubjectOffering;
use App\Services\ClassTeacherAssignmentService;
use App\Services\SchoolBookSessionSync;
use App\Support\ApiResponse;
use App\Support\SchoolBookStructure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClassSectionOfferingController extends Controller
{
    public function __construct(
        private readonly ClassTeacherAssignmentService $teachers,
        private readonly SchoolBookSessionSync $schoolBook,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $canViewStructure = $user?->can('viewAny', ClassSectionOffering::class) ?? false;
        $canRegisterPupils = $user?->can('create', StudentProfile::class) ?? false;

        if (! $canViewStructure && ! $canRegisterPupils) {
            throw new AuthorizationException;
        }

        if ($request->boolean('ensure_book') && ($user?->can('create', ClassSectionOffering::class) ?? false)) {
            $sessionId = $request->integer('ensure_session_id') ?: $request->integer('academic_session_id');
            if ($sessionId > 0) {
                $this->schoolBook->ensureBookForSession($sessionId);
            }
        }

        $offerings = ClassSectionOffering::query()
            ->with($this->defaultRelations())
            ->withCount([
                'enrollments as enrollments_count' => fn ($query) => $query->where('status', EnrollmentStatus::Active),
            ])
            ->when($request->integer('academic_session_id'), fn ($query, $id) => $query->where('academic_session_id', $id))
            ->when($request->integer('level_id'), function ($query, $id) {
                $query->whereHas('classSection.schoolClass', fn ($class) => $class->where('level_id', $id));
            })
            ->when($request->filled('campus_id'), fn ($query) => $query->where('campus_id', $request->integer('campus_id')))
            ->when($request->has('is_active') && $request->string('is_active')->isNotEmpty(), function ($query) use ($request) {
                $query->where('is_active', $request->boolean('is_active'));
            })
            ->when($request->boolean('book_only'), function ($query) {
                $forms = SchoolBookStructure::formNames();
                $classNames = SchoolBookStructure::schoolClassNames();
                $slugs = SchoolBookStructure::LEVEL_SLUGS;
                $formKeys = collect($forms)
                    ->map(fn (string $name) => mb_strtolower(str_replace(['–', '—', '−'], '-', $name)))
                    ->all();

                $query->whereHas('classSection', function ($section) use ($forms, $formKeys, $classNames, $slugs) {
                    $section->where(function ($nameQuery) use ($forms, $formKeys) {
                        $nameQuery->whereIn('name', $forms)
                            ->orWhereIn(
                                DB::raw("LOWER(REPLACE(REPLACE(REPLACE(name, '–', '-'), '—', '-'), '−', '-'))"),
                                $formKeys,
                            );
                    })->whereHas('schoolClass', function ($class) use ($classNames, $slugs) {
                        $class->whereIn('name', $classNames)
                            ->whereHas('level', fn ($level) => $level->whereIn('slug', $slugs));
                    });
                });
            })
            ->orderBy('id')
            ->get();

        return ApiResponse::success('Class offerings retrieved.', ClassSectionOfferingResource::collection($offerings)->resolve());
    }

    public function ensureBook(Request $request): JsonResponse
    {
        $this->authorize('create', ClassSectionOffering::class);

        $data = $request->validate([
            'academic_session_id' => ['required', 'integer', 'exists:academic_sessions,id'],
        ]);

        $this->schoolBook->ensureBookForSession((int) $data['academic_session_id']);

        $offerings = ClassSectionOffering::query()
            ->with($this->defaultRelations())
            ->withCount([
                'enrollments as enrollments_count' => fn ($query) => $query->where('status', EnrollmentStatus::Active),
            ])
            ->where('academic_session_id', (int) $data['academic_session_id'])
            ->whereHas('classSection', function ($section) {
                $forms = SchoolBookStructure::formNames();
                $classNames = SchoolBookStructure::schoolClassNames();
                $slugs = SchoolBookStructure::LEVEL_SLUGS;
                $section->whereIn('name', $forms)
                    ->whereHas('schoolClass', function ($class) use ($classNames, $slugs) {
                        $class->whereIn('name', $classNames)
                            ->whereHas('level', fn ($level) => $level->whereIn('slug', $slugs));
                    });
            })
            ->orderBy('id')
            ->get();

        return ApiResponse::success(
            'School-book forms are ready for this session.',
            ClassSectionOfferingResource::collection($offerings)->resolve(),
        );
    }

    public function store(StoreClassSectionOfferingRequest $request): JsonResponse
    {
        $offering = DB::transaction(function () use ($request) {
            $attributes = $request->safe()->except(['staff_profile_id', 'subject_ids']);
            $created = ClassSectionOffering::query()->create($attributes);

            if ($request->filled('staff_profile_id')) {
                $this->teachers->assign([
                    'staff_profile_id' => $request->integer('staff_profile_id'),
                    'class_section_offering_id' => $created->id,
                ], $request->user()?->id);
            }

            $this->attachSubjects($created, $request->input('subject_ids', []));

            return $created->fresh($this->defaultRelations())->loadCount([
                'enrollments as enrollments_count' => fn ($query) => $query->where('status', EnrollmentStatus::Active),
            ]);
        });

        return ApiResponse::success(
            'Class offering created.',
            (new ClassSectionOfferingResource($offering))->resolve(),
            201,
        );
    }

    public function update(UpdateClassSectionOfferingRequest $request, ClassSectionOffering $classSectionOffering): JsonResponse
    {
        $classSectionOffering->update($request->validated());

        return ApiResponse::success(
            'Class offering updated.',
            (new ClassSectionOfferingResource($classSectionOffering->fresh($this->defaultRelations())->loadCount([
                'enrollments as enrollments_count' => fn ($query) => $query->where('status', EnrollmentStatus::Active),
            ])))->resolve(),
        );
    }

    public function destroy(ClassSectionOffering $classSectionOffering): JsonResponse
    {
        $this->authorize('delete', $classSectionOffering);

        if ($classSectionOffering->subjectOfferings()->exists()) {
            throw ValidationException::withMessages([
                'offering' => 'This class offering cannot be deleted because subjects are attached.',
            ]);
        }

        if ($classSectionOffering->enrollments()->exists()) {
            throw ValidationException::withMessages([
                'offering' => 'This class offering cannot be deleted because pupils are enrolled.',
            ]);
        }

        if ($classSectionOffering->classTeacherAssignments()->exists()) {
            throw ValidationException::withMessages([
                'offering' => 'This class offering cannot be deleted because a class teacher was appointed. Close it instead.',
            ]);
        }

        $classSectionOffering->delete();

        return ApiResponse::success('Class offering deleted.');
    }

    /**
     * @return list<string>
     */
    private function defaultRelations(): array
    {
        return [
            'classSection.schoolClass.level',
            'academicSession',
            'campus',
            'activeClassTeacher.staff.user',
            'subjectOfferings.subject',
        ];
    }

    /**
     * @param  list<int|string>|mixed  $subjectIds
     */
    private function attachSubjects(ClassSectionOffering $offering, mixed $subjectIds): void
    {
        collect($subjectIds)
            ->filter()
            ->unique()
            ->each(function ($subjectId) use ($offering): void {
                SubjectOffering::query()->firstOrCreate([
                    'class_section_offering_id' => $offering->id,
                    'subject_id' => (int) $subjectId,
                ]);
            });
    }
}
