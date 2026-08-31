<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Enums\EnrollmentStatus;
use App\Models\Assignment;
use App\Models\AssignmentSubmission;
use App\Models\Document;
use App\Models\Enrollment;
use App\Models\SubjectOffering;
use App\Models\User;
use App\Notifications\SchoolNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssignmentService
{
    public function __construct(
        private readonly PeopleAccessService $access,
        private readonly DocumentService $documents,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): Assignment
    {
        $offeringId = (int) $attributes['class_section_offering_id'];
        $subjectId = (int) $attributes['subject_id'];
        $this->assertMayPost($actor, $offeringId, $subjectId);
        $this->assertSubjectOffered($offeringId, $subjectId);

        $staffId = $attributes['staff_profile_id'] ?? $actor->staffProfile?->id;
        if ($staffId === null) {
            throw ValidationException::withMessages([
                'staff_profile_id' => 'Only assigned teachers can set work for a class.',
            ]);
        }

        if (! $this->access->administers($actor) && (int) $staffId !== (int) $actor->staffProfile?->id) {
            throw new AuthorizationException;
        }

        $assignment = Assignment::query()->create([
            'class_section_offering_id' => $offeringId,
            'subject_id' => $subjectId,
            'staff_profile_id' => $staffId,
            'title' => $attributes['title'],
            'instructions' => $attributes['instructions'] ?? null,
            'due_on' => $attributes['due_on'],
        ]);

        $this->notifyClass($assignment);

        return $this->decorate($assignment, $actor);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Assignment $assignment, array $attributes, User $actor): Assignment
    {
        $this->assertMayPost($actor, (int) $assignment->class_section_offering_id, (int) $assignment->subject_id);

        $assignment->update([
            'title' => $attributes['title'] ?? $assignment->title,
            'instructions' => array_key_exists('instructions', $attributes) ? $attributes['instructions'] : $assignment->instructions,
            'due_on' => $attributes['due_on'] ?? $assignment->due_on,
        ]);

        return $this->decorate($assignment->fresh() ?? $assignment, $actor);
    }

    public function delete(Assignment $assignment, User $actor): void
    {
        $this->assertMayPost($actor, (int) $assignment->class_section_offering_id, (int) $assignment->subject_id);
        $assignment->delete();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function submit(Assignment $assignment, User $actor, array $attributes, ?UploadedFile $file): AssignmentSubmission
    {
        if (! $this->access->canSubmitAssignment($actor, $assignment)) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($assignment, $actor, $attributes, $file) {
            $studentId = (int) $actor->studentProfile?->id;
            $submission = AssignmentSubmission::query()->firstOrNew([
                'assignment_id' => $assignment->id,
                'student_profile_id' => $studentId,
            ]);

            if (array_key_exists('notes', $attributes)) {
                $submission->notes = $attributes['notes'];
            }

            $submission->submitted_at = now();
            $submission->save();

            if ($file !== null) {
                $document = $this->documents->storeFile(
                    $file,
                    DocumentType::AssignmentSubmission,
                    AssignmentSubmission::class,
                    (int) $submission->id,
                    $actor,
                );

                $previousId = $submission->document_id;
                $submission->document_id = $document->id;
                $submission->save();

                if ($previousId) {
                    $this->documents->delete(Document::query()->find($previousId));
                }
            }

            return $submission->load(['document', 'assignment']);
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function submissionsFor(Assignment $assignment, User $actor): Collection
    {
        if (! $this->access->canReviewAssignmentSubmissions($actor, $assignment)) {
            throw new AuthorizationException;
        }

        $enrolled = Enrollment::query()
            ->with('student.user')
            ->where('class_section_offering_id', $assignment->class_section_offering_id)
            ->where('status', EnrollmentStatus::Active)
            ->get()
            ->sortBy(fn (Enrollment $row) => mb_strtolower($row->student?->fullName() ?? ''));

        $byStudent = AssignmentSubmission::query()
            ->with(['document', 'assignment'])
            ->where('assignment_id', $assignment->id)
            ->get()
            ->keyBy('student_profile_id');

        return $enrolled->values()->map(function (Enrollment $enrollment) use ($byStudent) {
            $submission = $byStudent->get($enrollment->student_profile_id);
            $student = $enrollment->student;

            return [
                'student_profile_id' => $enrollment->student_profile_id,
                'student_name' => $student?->fullName(),
                'admission_number' => $student?->admission_number,
                'submitted' => $submission !== null,
                'submitted_at' => $submission?->submitted_at?->toIso8601String(),
                'late' => $submission?->isLate() ?? false,
                'notes' => $submission?->notes,
                'original_name' => $submission?->document?->original_name,
                'document_id' => $submission?->document_id,
                'download_url' => $submission?->document_id
                    ? '/api/v1/documents/'.$submission->document_id.'/download'
                    : null,
            ];
        });
    }

    public function visibleTo(User $user, ?int $offeringId = null, ?int $studentId = null)
    {
        $ids = $this->scopedOfferingIds($user, $offeringId, $studentId);
        $viewerStudentId = $this->viewerStudentId($user, $studentId);

        $query = Assignment::query()
            ->with($this->viewerRelations($viewerStudentId))
            ->withCount('submissions')
            ->whereIn('class_section_offering_id', $ids)
            ->orderBy('due_on')
            ->orderBy('id');

        return $query;
    }

    public function decorate(Assignment $assignment, User $user, ?int $studentId = null): Assignment
    {
        $viewerStudentId = $this->viewerStudentId($user, $studentId);

        $assignment->load($this->viewerRelations($viewerStudentId));
        $assignment->loadCount('submissions');

        return $assignment;
    }

    public function canView(User $user, Assignment $assignment): bool
    {
        return $this->access->canViewClassroomOffering($user, (int) $assignment->class_section_offering_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function viewerRelations(?int $viewerStudentId): array
    {
        $relations = [
            'subject',
            'staff.user',
            'classSectionOffering' => function ($query) {
                $query->with('classSection')
                    ->withCount([
                        'enrollments as on_roll' => fn ($enrollments) => $enrollments->where('status', EnrollmentStatus::Active),
                    ]);
            },
        ];

        if ($viewerStudentId) {
            $relations['submissions'] = function ($query) use ($viewerStudentId) {
                $query->where('student_profile_id', $viewerStudentId)->with(['document', 'assignment']);
            };
        }

        return $relations;
    }

    private function viewerStudentId(User $user, ?int $studentId): ?int
    {
        if ($this->access->isStudent($user)) {
            return $user->studentProfile?->id;
        }

        if ($this->access->isParent($user) && $studentId) {
            return $studentId;
        }

        return null;
    }

    /**
     * @return Collection<int, int>
     */
    private function scopedOfferingIds(User $user, ?int $offeringId, ?int $studentId)
    {
        $ids = $this->access->classroomOfferingIds($user);

        if ($studentId) {
            if ($this->access->isParent($user) && ! $this->access->linkedStudentIds($user)->contains($studentId)) {
                throw new AuthorizationException;
            }
            if ($this->access->isStudent($user) && (int) $user->studentProfile?->id !== $studentId) {
                throw new AuthorizationException;
            }

            $studentOfferings = Enrollment::query()
                ->where('student_profile_id', $studentId)
                ->where('status', EnrollmentStatus::Active)
                ->pluck('class_section_offering_id');

            $ids = $ids->intersect($studentOfferings)->values();
        }

        if ($offeringId) {
            if (! $ids->contains($offeringId)) {
                throw new AuthorizationException;
            }

            return collect([$offeringId]);
        }

        return $ids;
    }

    private function notifyClass(Assignment $assignment): void
    {
        $studentIds = Enrollment::query()
            ->where('class_section_offering_id', $assignment->class_section_offering_id)
            ->where('status', EnrollmentStatus::Active)
            ->pluck('student_profile_id');

        $users = User::query()
            ->where(function ($query) use ($studentIds) {
                $query->whereHas('studentProfile', fn ($student) => $student->whereIn('id', $studentIds))
                    ->orWhereHas('guardianProfile.students', fn ($student) => $student->whereIn('student_profiles.id', $studentIds));
            })
            ->get();

        $users->each(function (User $user) use ($assignment) {
            $user->notify(new SchoolNotice(
                $assignment->title,
                'Due '.$assignment->due_on?->toDateString(),
                'assignment',
                $assignment->id,
            ));
        });
    }

    private function assertMayPost(User $actor, int $offeringId, int $subjectId): void
    {
        if (! $this->access->canPostClassroomWork($actor, $offeringId, $subjectId)) {
            throw new AuthorizationException;
        }
    }

    private function assertSubjectOffered(int $offeringId, int $subjectId): void
    {
        $exists = SubjectOffering::query()
            ->where('class_section_offering_id', $offeringId)
            ->where('subject_id', $subjectId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'subject_id' => 'This subject is not offered in the selected class.',
            ]);
        }
    }
}
