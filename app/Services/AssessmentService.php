<?php

namespace App\Services;

use App\Enums\AssessmentKind;
use App\Enums\AttendanceStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use App\Enums\UserStatus;
use App\Models\AcademicSession;
use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\AttendanceRecord;
use App\Models\ClassSectionOffering;
use App\Models\ClassTeacherAssignment;
use App\Models\Enrollment;
use App\Models\GradeScale;
use App\Models\SchoolSetting;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\SubjectOffering;
use App\Models\Term;
use App\Models\TermResult;
use App\Models\TermSummary;
use App\Models\User;
use App\Support\SchoolIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AssessmentService
{
    public function __construct(private readonly PeopleAccessService $access) {}

    /**
     * @return array<string, mixed>
     */
    public function contexts(User $actor): array
    {
        $ids = $this->access->administers($actor)
            ? ClassSectionOffering::query()->pluck('id')
            : $this->access->assignedOfferingIds($actor);

        $offerings = ClassSectionOffering::query()
            ->with(['classSection', 'academicSession', 'subjects' => fn ($query) => $query->orderBy('name')])
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->get();

        $settings = SchoolSetting::query()->first();

        return [
            'current_term_id' => $settings?->current_term_id,
            'current_academic_session_id' => $settings?->current_academic_session_id,
            'assessment_types' => AssessmentType::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get()
                ->map(fn (AssessmentType $type) => $this->typePayload($type))
                ->values()
                ->all(),
            'grade_scales' => GradeScale::query()->orderBy('sort_order')->get()->map(fn (GradeScale $scale) => [
                'id' => $scale->id,
                'min_score' => (float) $scale->min_score,
                'max_score' => (float) $scale->max_score,
                'grade' => $scale->grade,
                'remark' => $scale->remark,
            ])->values()->all(),
            'sessions' => AcademicSession::query()
                ->orderByDesc('starts_on')
                ->get()
                ->map(fn (AcademicSession $session) => [
                    'id' => $session->id,
                    'name' => $session->name,
                    'status' => $session->status?->value,
                ])
                ->values()
                ->all(),
            'offerings' => $offerings->map(function (ClassSectionOffering $offering) use ($actor) {
                $subjects = $offering->subjects;
                $isClassTeacher = $this->access->classTeacherOfferingIds($actor)->contains($offering->id);

                if (! $this->access->administers($actor) && $this->access->isTeacher($actor) && ! $isClassTeacher) {
                    $allowed = $this->access->assignedSubjectIdsForOffering($actor, $offering->id);
                    $subjects = $subjects->whereIn('id', $allowed)->values();
                }

                return [
                    'id' => $offering->id,
                    'form' => $offering->classSection?->name,
                    'academic_session_id' => $offering->academic_session_id,
                    'session_name' => $offering->academicSession?->name,
                    'subjects' => $subjects->map(fn (Subject $subject) => [
                        'id' => $subject->id,
                        'name' => $subject->name,
                        'code' => $subject->code,
                        'can_enter' => $this->access->canEnterScoresFor($actor, $offering->id, $subject->id),
                    ])->values()->all(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function register(
        int $offeringId,
        int $subjectId,
        ?int $termId,
        ?int $sessionId,
        ?int $assessmentTypeId,
        bool $finalView,
        User $actor,
    ): array {
        $this->assertSubjectOffered($offeringId, $subjectId);

        if (! $this->access->administers($actor) && $this->access->isTeacher($actor)) {
            $isClassTeacher = $this->access->classTeacherOfferingIds($actor)->contains($offeringId);
            $teachesSubject = $this->access->assignedSubjectIdsForOffering($actor, $offeringId)->contains($subjectId);

            if (! $isClassTeacher && ! $teachesSubject) {
                throw new AuthorizationException;
            }
        } elseif (! $this->access->canViewScoresForOffering($actor, $offeringId)) {
            throw new AuthorizationException;
        }

        $term = $this->resolveTerm($termId, $sessionId, $offeringId);
        $offering = ClassSectionOffering::query()->with(['classSection', 'academicSession'])->findOrFail($offeringId);
        $subject = Subject::query()->findOrFail($subjectId);
        $type = $finalView ? null : $this->assessmentType($assessmentTypeId);
        $enrollments = $this->enrollmentsFor($offeringId);
        $sessionLocked = $this->sessionIsLocked($term);
        $mayAmend = $this->access->administers($actor) && $type !== null && ! $sessionLocked;
        $canFill = $type !== null && $this->access->canEnterScoresFor($actor, $offeringId, $subjectId)
            && ! $sessionLocked;

        $scores = AssessmentScore::query()
            ->with('recorder:id,name')
            ->where('term_id', $term->id)
            ->where('subject_id', $subjectId)
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get();

        $results = TermResult::query()
            ->where('term_id', $term->id)
            ->where('subject_id', $subjectId)
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->keyBy('enrollment_id');

        $byEnrollment = $scores->groupBy('enrollment_id');

        $students = $enrollments->values()->map(function (Enrollment $enrollment, int $index) use ($type, $finalView, $byEnrollment, $results, $mayAmend, $canFill) {
            $cells = $byEnrollment->get($enrollment->id, collect());
            $result = $results->get($enrollment->id);
            $score = null;
            $grade = $result?->grade;
            $remark = $result?->remark;
            $display = $finalView ? ($result !== null ? (float) $result->total : null) : null;
            $recorded = false;
            $enteredBy = null;

            if ($type !== null) {
                $cell = $cells->firstWhere('assessment_type_id', $type->id);
                $score = $cell?->score !== null ? (float) $cell->score : null;
                $display = $score;
                $recorded = $cell !== null;
                $enteredBy = $cell?->recorder?->name;
                [$grade, $remark] = $this->previewGrade($score, (float) $type->max_score);
            }

            return [
                'enrollment_id' => $enrollment->id,
                'student_profile_id' => $enrollment->student_profile_id,
                'admission_number' => $enrollment->student?->admission_number,
                'full_name' => $enrollment->student?->fullName(),
                'index' => $index + 1,
                'score' => $display,
                'ca_total' => $result !== null ? (float) $result->ca_total : null,
                'exam_score' => $result !== null ? (float) $result->exam_score : null,
                'total' => $result !== null ? (float) $result->total : null,
                'grade' => $grade,
                'remark' => $remark,
                'recorded' => $recorded,
                'entered_by' => $enteredBy,
                'can_enter' => $mayAmend || ($canFill && ! $recorded),
            ];
        })->all();

        $recorded = collect($students)->filter(fn (array $row) => $row['score'] !== null);

        return [
            'class_section_offering_id' => $offering->id,
            'form' => $offering->classSection?->name,
            'subject_id' => $subject->id,
            'subject_name' => $subject->name,
            'term_id' => $term->id,
            'term_name' => $term->name,
            'academic_session_id' => $term->academic_session_id,
            'session_name' => $term->academicSession?->name,
            'session_status' => $term->academicSession?->status?->value,
            'view' => $finalView ? 'results' : 'scores',
            'assessment_type' => $type ? $this->typePayload($type) : null,
            'can_enter' => $mayAmend || collect($students)->contains(fn (array $row) => $row['can_enter']),
            'can_amend' => $mayAmend,
            'locked' => $sessionLocked,
            'max_score' => $finalView ? 100.0 : ($type ? (float) $type->max_score : null),
            'summary' => [
                'total' => count($students),
                'recorded' => $recorded->count(),
                'average' => $recorded->isEmpty() ? null : round($recorded->avg('score'), 2),
                'highest' => $recorded->isEmpty() ? null : $recorded->max('score'),
                'completed_percent' => count($students) === 0
                    ? 0
                    : (int) round(($recorded->count() / count($students)) * 100),
            ],
            'students' => $students,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function record(array $attributes, User $actor): AssessmentScore
    {
        $enrollment = $this->enrollmentForScoring($attributes['enrollment_id'] ?? null);
        $type = $this->assessmentType($attributes['assessment_type_id'] ?? null);
        $term = $this->resolveTerm(
            isset($attributes['term_id']) ? (int) $attributes['term_id'] : null,
            isset($attributes['academic_session_id']) ? (int) $attributes['academic_session_id'] : null,
            (int) $enrollment->class_section_offering_id,
        );
        $subjectId = (int) $attributes['subject_id'];

        $this->assertWritable($actor, $enrollment, $subjectId, $term);
        $this->assertScoreInRange($attributes['score'] ?? null, $type, 'score');

        $existing = $this->existingScore((int) $enrollment->id, (int) $term->id, $subjectId, (int) $type->id);
        $this->assertStaffMayWriteCell($actor, $existing, $attributes['score'] ?? null, 'score');

        if ($existing !== null && ! $this->access->administers($actor)
            && $this->sameScore($existing->score, $attributes['score'] ?? null)) {
            return $existing->fresh($this->scoreRelations());
        }

        $score = DB::transaction(function () use ($enrollment, $term, $subjectId, $type, $attributes, $actor) {
            $row = AssessmentScore::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'term_id' => $term->id,
                    'subject_id' => $subjectId,
                    'assessment_type_id' => $type->id,
                ],
                [
                    'score' => $attributes['score'],
                    'entered_by' => $actor->id,
                ],
            );

            $this->recalculateSubject($enrollment->class_section_offering_id, $subjectId, $term->id);
            $this->recalculateOfferingSummaries((int) $enrollment->class_section_offering_id, $term->id);

            return $row->fresh($this->scoreRelations());
        });

        return $score;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return Collection<int, AssessmentScore>
     */
    public function bulk(array $payload, User $actor): Collection
    {
        $offeringId = (int) $payload['class_section_offering_id'];
        $subjectId = (int) $payload['subject_id'];
        $type = $this->assessmentType($payload['assessment_type_id'] ?? null);
        $term = $this->resolveTerm(
            isset($payload['term_id']) ? (int) $payload['term_id'] : null,
            isset($payload['academic_session_id']) ? (int) $payload['academic_session_id'] : null,
            $offeringId,
        );

        $this->assertSubjectOffered($offeringId, $subjectId);
        $this->assertMayEnter($actor, $offeringId, $subjectId);
        $this->assertSessionWritable($term);

        $rows = $payload['scores'] ?? [];

        return DB::transaction(function () use ($rows, $offeringId, $subjectId, $type, $term, $actor) {
            $saved = collect();

            foreach ($rows as $index => $row) {
                $enrollment = $this->enrollmentForScoring($row['enrollment_id'] ?? null);

                if ((int) $enrollment->class_section_offering_id !== $offeringId) {
                    throw ValidationException::withMessages([
                        "scores.{$index}.enrollment_id" => 'This pupil is not enrolled in the selected class.',
                    ]);
                }

                $existing = $this->existingScore((int) $enrollment->id, (int) $term->id, $subjectId, (int) $type->id);
                $incoming = array_key_exists('score', $row) ? $row['score'] : null;
                $this->assertStaffMayWriteCell($actor, $existing, $incoming, "scores.{$index}.score");

                if (! array_key_exists('score', $row) || $row['score'] === null || $row['score'] === '') {
                    if ($existing !== null && ! $this->access->administers($actor)) {
                        continue;
                    }

                    AssessmentScore::query()
                        ->where('enrollment_id', $enrollment->id)
                        ->where('term_id', $term->id)
                        ->where('subject_id', $subjectId)
                        ->where('assessment_type_id', $type->id)
                        ->delete();

                    continue;
                }

                if ($existing !== null && ! $this->access->administers($actor)
                    && $this->sameScore($existing->score, $row['score'])) {
                    $saved->push($existing);
                    continue;
                }

                $this->assertScoreInRange($row['score'], $type, "scores.{$index}.score");

                $saved->push(AssessmentScore::query()->updateOrCreate(
                    [
                        'enrollment_id' => $enrollment->id,
                        'term_id' => $term->id,
                        'subject_id' => $subjectId,
                        'assessment_type_id' => $type->id,
                    ],
                    [
                        'score' => $row['score'],
                        'entered_by' => $actor->id,
                    ],
                ));
            }

            $this->recalculateSubject($offeringId, $subjectId, $term->id);
            $this->recalculateOfferingSummaries($offeringId, $term->id);

            return AssessmentScore::query()
                ->with($this->scoreRelations())
                ->where('term_id', $term->id)
                ->where('subject_id', $subjectId)
                ->where('assessment_type_id', $type->id)
                ->whereIn('enrollment_id', collect($rows)->pluck('enrollment_id'))
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function resultsForStudent(?int $studentId, ?int $termId, ?int $sessionId, User $actor): array
    {
        $studentId = $this->resolveStudentId($studentId, $actor);
        $student = StudentProfile::query()->findOrFail($studentId);
        abort_unless($this->access->canViewStudent($actor, $student), 403);

        $enrollment = $this->enrollmentForStudentSession($studentId, $termId, $sessionId);
        $hint = $this->periodHint($termId, $sessionId);

        if ($enrollment === null) {
            return array_merge([
                'student_profile_id' => $studentId,
                'student_name' => $student->fullName(),
                'admission_number' => $student->admission_number,
                'enrollment_id' => null,
                'form' => null,
                'term_id' => $hint['term_id'],
                'term_name' => $hint['term_name'],
                'academic_session_id' => $hint['academic_session_id'],
                'session_name' => $hint['session_name'],
                'average' => null,
                'class_position' => null,
                'class_size' => null,
                'highest' => null,
                'can_amend' => false,
                'assessment_types' => [],
                'periods' => $this->resultPeriods($hint['term_id']),
                'results' => [],
            ], $this->reportChrome($student, null, $hint['term_id'] ? Term::query()->find($hint['term_id']) : null, $actor));
        }

        $term = $this->resolveTerm($termId, $sessionId, (int) $enrollment->class_section_offering_id);

        $types = AssessmentType::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $results = TermResult::query()
            ->with('subject')
            ->where('enrollment_id', $enrollment->id)
            ->where('term_id', $term->id)
            ->orderBy('id')
            ->get();

        $offered = SubjectOffering::query()
            ->with('subject')
            ->where('class_section_offering_id', $enrollment->class_section_offering_id)
            ->get();

        $bySubject = $results->keyBy('subject_id');
        $cells = AssessmentScore::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('term_id', $term->id)
            ->get()
            ->groupBy('subject_id');

        $rows = $offered->map(function (SubjectOffering $offering) use ($bySubject, $cells, $types) {
            $result = $bySubject->get($offering->subject_id);
            $subjectCells = $cells->get($offering->subject_id, collect())->keyBy('assessment_type_id');

            $scores = $types->map(function (AssessmentType $type) use ($subjectCells) {
                $cell = $subjectCells->get($type->id);

                return [
                    'assessment_type_id' => $type->id,
                    'name' => $type->name,
                    'kind' => $type->kind?->value,
                    'max_score' => (float) $type->max_score,
                    'score' => $cell?->score !== null ? (float) $cell->score : null,
                    'recorded' => $cell !== null,
                ];
            })->values();

            $caTotal = 0.0;
            $examScore = 0.0;
            $hasCa = false;
            $hasExam = false;

            foreach ($scores as $paper) {
                if ($paper['score'] === null) {
                    continue;
                }

                if ($paper['kind'] === AssessmentKind::Examination->value) {
                    $examScore += $paper['score'];
                    $hasExam = true;
                } else {
                    $caTotal += $paper['score'];
                    $hasCa = true;
                }
            }

            $hasMark = $hasCa || $hasExam;
            $total = $hasMark ? round($caTotal + $examScore, 2) : null;
            $grade = $hasMark ? $result?->grade : null;
            $remark = $hasMark ? $result?->remark : null;

            if ($hasMark && ($grade === null || $remark === null) && $total !== null) {
                [$grade, $remark] = $this->gradeForTotal($total);
            }

            return [
                'subject_id' => $offering->subject_id,
                'subject_name' => $offering->subject?->name,
                'ca_total' => $hasCa ? round($caTotal, 2) : null,
                'exam_score' => $hasExam ? round($examScore, 2) : null,
                'total' => $total,
                'grade' => $grade,
                'remark' => $remark,
                'scores' => $scores->all(),
            ];
        })->values()->all();

        $summary = TermSummary::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('term_id', $term->id)
            ->first();

        $highest = $results->sortByDesc(fn (TermResult $row) => (float) $row->total)->first();

        return array_merge([
            'student_profile_id' => $studentId,
            'student_name' => $student->fullName(),
            'admission_number' => $student->admission_number,
            'enrollment_id' => $enrollment->id,
            'form' => $enrollment->classSectionOffering?->classSection?->name,
            'term_id' => $term->id,
            'term_name' => $term->name,
            'academic_session_id' => $term->academic_session_id,
            'session_name' => $term->academicSession?->name,
            'average' => $summary?->average !== null ? (float) $summary->average : null,
            'class_position' => $summary?->class_position,
            'class_size' => $summary?->class_size,
            'highest' => $highest ? [
                'subject_name' => $highest->subject?->name,
                'total' => (float) $highest->total,
            ] : null,
            'can_amend' => $this->access->administers($actor) && ! $this->sessionIsLocked($term),
            'assessment_types' => $types->map(fn (AssessmentType $type) => $this->typePayload($type))->values()->all(),
            'periods' => $this->resultPeriods($term->id),
            'results' => $rows,
        ], $this->reportChrome($student, $enrollment, $term, $actor, $summary));
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function saveComments(int $enrollmentId, int $termId, array $attributes, User $actor): array
    {
        $enrollment = Enrollment::query()->with('student')->find($enrollmentId);

        if ($enrollment === null || $enrollment->student === null) {
            throw ValidationException::withMessages([
                'enrollment_id' => 'The selected enrolment does not exist.',
            ]);
        }

        abort_unless($this->access->canViewStudent($actor, $enrollment->student), 403);

        $term = Term::query()->with('academicSession')->findOrFail($termId);
        $this->assertSessionWritable($term);

        $offeringId = (int) $enrollment->class_section_offering_id;
        $wantsClass = array_key_exists('class_teacher_comment', $attributes);
        $wantsPrincipal = array_key_exists('principal_comment', $attributes);

        if (! $wantsClass && ! $wantsPrincipal) {
            throw ValidationException::withMessages([
                'class_teacher_comment' => 'Write a class-teacher or principal comment.',
            ]);
        }

        if ($wantsClass && ! $this->access->canWriteClassTeacherComment($actor, $offeringId)) {
            throw new AuthorizationException;
        }

        if ($wantsPrincipal && ! $this->access->canWritePrincipalComment($actor)) {
            throw new AuthorizationException;
        }

        $payload = [];

        if ($wantsClass) {
            $payload['class_teacher_comment'] = $this->cleanComment($attributes['class_teacher_comment'] ?? null);
            $payload['class_teacher_commented_by'] = $actor->id;
        }

        if ($wantsPrincipal) {
            $payload['principal_comment'] = $this->cleanComment($attributes['principal_comment'] ?? null);
            $payload['principal_commented_by'] = $actor->id;
        }

        TermSummary::query()->updateOrCreate(
            [
                'enrollment_id' => $enrollment->id,
                'term_id' => $term->id,
            ],
            $payload,
        );

        return $this->resultsForStudent((int) $enrollment->student_profile_id, $term->id, null, $actor);
    }

    public function recalculateSubject(int $offeringId, int $subjectId, int $termId): void
    {
        $enrollments = $this->enrollmentsFor($offeringId);
        $types = AssessmentType::query()->where('is_active', true)->get()->keyBy('id');

        $scores = AssessmentScore::query()
            ->where('term_id', $termId)
            ->where('subject_id', $subjectId)
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->get()
            ->groupBy('enrollment_id');

        foreach ($enrollments as $enrollment) {
            $cells = $scores->get($enrollment->id, collect());
            $ca = 0.0;
            $exam = 0.0;

            foreach ($cells as $cell) {
                $kind = $types->get($cell->assessment_type_id)?->kind;
                $value = (float) $cell->score;

                if ($kind === AssessmentKind::Examination) {
                    $exam += $value;
                } else {
                    $ca += $value;
                }
            }

            $total = round($ca + $exam, 2);
            [$grade, $remark] = $this->gradeForTotal($total);

            TermResult::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'term_id' => $termId,
                    'subject_id' => $subjectId,
                ],
                [
                    'ca_total' => round($ca, 2),
                    'exam_score' => round($exam, 2),
                    'total' => $total,
                    'grade' => $grade,
                    'remark' => $remark,
                ],
            );
        }
    }

    public function recalculateOfferingSummaries(int $offeringId, int $termId): void
    {
        $enrollments = $this->enrollmentsFor($offeringId);
        $subjectIds = SubjectOffering::query()
            ->where('class_section_offering_id', $offeringId)
            ->pluck('subject_id');

        $classSize = $enrollments->count();
        $results = TermResult::query()
            ->where('term_id', $termId)
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->whereIn('subject_id', $subjectIds)
            ->get()
            ->groupBy('enrollment_id');

        $averages = [];

        foreach ($enrollments as $enrollment) {
            if ($subjectIds->isEmpty()) {
                $average = 0.0;
            } else {
                $bySubject = $results->get($enrollment->id, collect())->keyBy('subject_id');
                $sum = 0.0;
                foreach ($subjectIds as $subjectId) {
                    $sum += (float) ($bySubject->get($subjectId)?->total ?? 0);
                }
                $average = round($sum / $subjectIds->count(), 2);
            }

            $averages[$enrollment->id] = $average;

            TermSummary::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'term_id' => $termId,
                ],
                [
                    'average' => $average,
                    'class_size' => $classSize,
                ],
            );
        }

        arsort($averages, SORT_NUMERIC);
        $position = 0;
        $index = 0;
        $last = null;

        foreach ($averages as $enrollmentId => $average) {
            $index++;
            if ($last === null || (float) $average !== (float) $last) {
                $position = $index;
                $last = $average;
            }

            TermSummary::query()
                ->where('enrollment_id', $enrollmentId)
                ->where('term_id', $termId)
                ->update(['class_position' => $position, 'class_size' => $classSize]);
        }
    }

    /**
     * @return list<string>
     */
    public function scoreRelations(): array
    {
        return ['enrollment.student', 'term.academicSession', 'subject', 'assessmentType', 'recorder'];
    }

    public function resolveTerm(?int $termId, ?int $sessionId, ?int $offeringId = null): Term
    {
        if ($termId) {
            $term = Term::query()->with('academicSession')->find($termId);
            abort_unless($term !== null, 404);

            return $term;
        }

        $settings = SchoolSetting::query()->first();
        $current = $settings?->currentTerm;

        if ($offeringId !== null && $sessionId !== null) {
            $offeringSessionId = ClassSectionOffering::query()->where('id', $offeringId)->value('academic_session_id');
            if ($offeringSessionId) {
                $sessionId = (int) $offeringSessionId;
            }
        }

        // A pupil/parent "This term" view does not send a session. Use the live
        // school term so marks entered on the current desk appear even when the
        // class offering still belongs to a previous year.
        if ($sessionId === null && $current !== null) {
            return $current->loadMissing('academicSession');
        }

        if ($sessionId === null && $offeringId !== null) {
            $sessionId = ClassSectionOffering::query()->where('id', $offeringId)->value('academic_session_id');
        }

        if ($sessionId === null) {
            $sessionId = $settings?->current_academic_session_id;
        }

        abort_unless($sessionId !== null, 404);

        $session = AcademicSession::query()->with('terms')->find($sessionId);
        abort_unless($session !== null, 404);

        if ($current !== null && (int) $current->academic_session_id === (int) $session->id) {
            return $current->loadMissing('academicSession');
        }

        $term = $session->terms->sortBy('term_number')->first();

        if ($term === null) {
            throw ValidationException::withMessages([
                'academic_session_id' => 'This session has no terms.',
            ]);
        }

        return $term->loadMissing('academicSession');
    }

    private function resolveStudentId(?int $studentId, User $actor): int
    {
        if ($this->access->isStudent($actor) && ! $this->access->administers($actor)) {
            abort_unless($actor->studentProfile !== null, 403);

            if ($studentId !== null && $studentId !== $actor->studentProfile->id) {
                throw new AuthorizationException;
            }

            return $actor->studentProfile->id;
        }

        if ($studentId !== null) {
            if ($this->access->isParent($actor) && ! $this->access->administers($actor)
                && ! $this->access->linkedStudentIds($actor)->contains($studentId)) {
                throw new AuthorizationException;
            }

            return $studentId;
        }

        if ($this->access->isParent($actor) && ! $this->access->administers($actor)) {
            $first = $this->access->linkedStudentIds($actor)->first();
            abort_unless($first !== null, 404);

            return (int) $first;
        }

        throw ValidationException::withMessages([
            'student_profile_id' => 'A pupil must be selected.',
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resultPeriods(?int $selectedTermId): array
    {
        $settings = SchoolSetting::query()->first();
        $currentTermId = $settings?->current_term_id;
        $currentSessionId = $settings?->current_academic_session_id;

        $sessions = AcademicSession::query()
            ->with(['terms' => fn ($query) => $query->orderBy('term_number')->orderBy('id')])
            ->orderByDesc('starts_on')
            ->orderByDesc('id')
            ->get();

        return $sessions->flatMap(function (AcademicSession $session) use ($currentTermId, $currentSessionId, $selectedTermId) {
            return $session->terms->map(function (Term $term) use ($session, $currentTermId, $currentSessionId, $selectedTermId) {
                return [
                    'id' => $term->id,
                    'name' => $term->name,
                    'term_number' => $term->term_number,
                    'academic_session_id' => $session->id,
                    'session_name' => $session->name,
                    'is_current' => $currentTermId !== null && (int) $term->id === (int) $currentTermId,
                    'is_current_session' => $currentSessionId !== null && (int) $session->id === (int) $currentSessionId,
                    'selected' => $selectedTermId !== null && (int) $term->id === (int) $selectedTermId,
                ];
            });
        })->values()->all();
    }

    /**
     * @return array{term_id: int|null, term_name: string|null, academic_session_id: int|null, session_name: string|null}
     */
    private function periodHint(?int $termId, ?int $sessionId): array
    {
        $term = $termId ? Term::query()->with('academicSession')->find($termId) : null;

        if ($term === null && $sessionId) {
            $session = AcademicSession::query()->with('terms')->find($sessionId);
            $term = $session?->terms->sortBy('term_number')->first();
            $term?->setRelation('academicSession', $session);
        }

        if ($term === null) {
            $term = SchoolSetting::query()->first()?->currentTerm?->loadMissing('academicSession');
        }

        return [
            'term_id' => $term?->id,
            'term_name' => $term?->name,
            'academic_session_id' => $term?->academic_session_id,
            'session_name' => $term?->academicSession?->name,
        ];
    }

    private function enrollmentForStudentSession(int $studentId, ?int $termId, ?int $sessionId): ?Enrollment
    {
        $sessionId ??= Term::query()->where('id', $termId)->value('academic_session_id');

        $query = Enrollment::query()
            ->with(['student', 'classSectionOffering.classSection', 'academicSession'])
            ->where('student_profile_id', $studentId);

        if ($sessionId) {
            $matched = (clone $query)
                ->where('academic_session_id', $sessionId)
                ->orderByDesc('enrolled_on')
                ->first();

            if ($matched !== null) {
                return $matched;
            }
        }

        return $query->orderByDesc('enrolled_on')->first();
    }

    private function enrollmentForScoring(mixed $id): Enrollment
    {
        $enrollment = Enrollment::query()->with(['student', 'classSectionOffering'])->find($id);

        if ($enrollment === null) {
            throw ValidationException::withMessages([
                'enrollment_id' => 'The selected enrolment does not exist.',
            ]);
        }

        return $enrollment;
    }

    private function enrollmentsFor(int $offeringId): Collection
    {
        return Enrollment::query()
            ->with('student')
            ->where('class_section_offering_id', $offeringId)
            ->where('status', EnrollmentStatus::Active)
            ->orderBy('id')
            ->get();
    }

    private function assessmentType(mixed $id): AssessmentType
    {
        $type = AssessmentType::query()->find($id);

        if ($type === null || ! $type->is_active) {
            throw ValidationException::withMessages([
                'assessment_type_id' => 'The selected assessment type is not available.',
            ]);
        }

        return $type;
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

    private function assertMayEnter(User $actor, int $offeringId, int $subjectId): void
    {
        if (! $this->access->canEnterScoresFor($actor, $offeringId, $subjectId)) {
            throw new AuthorizationException;
        }
    }

    private function assertWritable(User $actor, Enrollment $enrollment, int $subjectId, Term $term): void
    {
        $this->assertSubjectOffered((int) $enrollment->class_section_offering_id, $subjectId);
        $this->assertMayEnter($actor, (int) $enrollment->class_section_offering_id, $subjectId);
        $this->assertSessionWritable($term);
    }

    private function assertSessionWritable(Term $term): void
    {
        $term->loadMissing('academicSession');

        if ($this->sessionIsLocked($term)) {
            throw ValidationException::withMessages([
                'term_id' => 'Scores cannot be entered for an archived session.',
            ]);
        }
    }

    private function sessionIsLocked(Term $term): bool
    {
        $term->loadMissing('academicSession');

        return $term->academicSession?->status === SessionStatus::Archived
            || $term->status === SessionStatus::Archived;
    }

    private function assertScoreInRange(mixed $score, AssessmentType $type, string $key): void
    {
        if (! is_numeric($score)) {
            throw ValidationException::withMessages([
                $key => 'A numeric score is required.',
            ]);
        }

        $value = (float) $score;
        $max = (float) $type->max_score;

        if ($value < 0 || $value > $max) {
            throw ValidationException::withMessages([
                $key => "Score must be between 0 and {$max}.",
            ]);
        }
    }

    private function existingScore(int $enrollmentId, int $termId, int $subjectId, int $typeId): ?AssessmentScore
    {
        return AssessmentScore::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('term_id', $termId)
            ->where('subject_id', $subjectId)
            ->where('assessment_type_id', $typeId)
            ->first();
    }

    private function sameScore(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null || $right === '') {
            return false;
        }

        return round((float) $left, 2) === round((float) $right, 2);
    }

    private function assertStaffMayWriteCell(User $actor, ?AssessmentScore $existing, mixed $incoming, string $key): void
    {
        if ($this->access->administers($actor) || $existing === null) {
            return;
        }

        if ($incoming === null || $incoming === '' || ! $this->sameScore($existing->score, $incoming)) {
            throw ValidationException::withMessages([
                $key => 'This mark has been sealed. Only the office can change it.',
            ]);
        }
    }

    private function previewGrade(?float $score, float $max): array
    {
        if ($score === null || $max <= 0) {
            return [null, null];
        }

        return $this->gradeForTotal(round(($score / $max) * 100, 2));
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function gradeForTotal(float $total): array
    {
        $clamped = max(0, min(100, $total));
        $scale = GradeScale::forScore($clamped);

        return [$scale?->grade, $scale?->remark];
    }

    /**
     * @return array<string, mixed>
     */
    private function typePayload(AssessmentType $type): array
    {
        return [
            'id' => $type->id,
            'kind' => $type->kind?->value,
            'name' => $type->name,
            'max_score' => (float) $type->max_score,
            'sort_order' => $type->sort_order,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reportChrome(StudentProfile $student, ?Enrollment $enrollment, ?Term $term, User $actor, ?TermSummary $summary = null): array
    {
        $offeringId = $enrollment ? (int) $enrollment->class_section_offering_id : null;
        $locked = $term !== null && $this->sessionIsLocked($term);

        return [
            'gender' => $student->gender?->value,
            'school' => $this->schoolLetterhead(),
            'class_teacher' => $this->classTeacherName($offeringId),
            'principal' => $this->principalName(),
            'attendance' => $enrollment && $term ? $this->termAttendance((int) $enrollment->id, $term) : null,
            'resumption_on' => $term ? $this->resumptionOn($term) : null,
            'comments' => [
                'class_teacher' => $summary?->class_teacher_comment,
                'principal' => $summary?->principal_comment,
            ],
            'can_comment' => [
                'class_teacher' => $offeringId !== null && ! $locked && $this->access->canWriteClassTeacherComment($actor, $offeringId),
                'principal' => $offeringId !== null && ! $locked && $this->access->canWritePrincipalComment($actor),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function schoolLetterhead(): array
    {
        $settings = SchoolSetting::query()->first();

        return [
            'name' => SchoolIdentity::name(),
            'short_name' => filled($settings?->short_name) ? $settings->short_name : 'SRS',
            'motto' => SchoolIdentity::motto(),
            'address' => SchoolIdentity::addressText(),
            'phone' => SchoolIdentity::phone(),
            'email' => SchoolIdentity::email(),
            'logo_path' => $settings?->logo_path ?: '/site/Image/logo_main.png',
        ];
    }

    private function classTeacherName(?int $offeringId): ?string
    {
        if ($offeringId === null) {
            return null;
        }

        $row = ClassTeacherAssignment::query()
            ->with('staff.user')
            ->where('class_section_offering_id', $offeringId)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        $name = trim((string) ($row?->staff?->user?->name ?? ''));

        return $name !== '' ? $name : null;
    }

    private function principalName(): ?string
    {
        $name = User::query()
            ->where('status', UserStatus::Active)
            ->whereHas('roles', fn ($query) => $query->where('slug', RoleSlug::Principal))
            ->orderBy('id')
            ->value('name');

        $name = trim((string) $name);

        return $name !== '' ? $name : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function termAttendance(int $enrollmentId, Term $term): array
    {
        $query = AttendanceRecord::query()->where('enrollment_id', $enrollmentId);

        if ($term->starts_on && $term->ends_on) {
            $query->whereDate('marked_on', '>=', $term->starts_on->toDateString())
                ->whereDate('marked_on', '<=', $term->ends_on->toDateString());
        } else {
            $query->whereHas(
                'enrollment',
                fn ($enrollment) => $enrollment->where('academic_session_id', $term->academic_session_id),
            );
        }

        $records = $query->get();
        $present = $records->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Present)->count();
        $late = $records->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Late)->count();
        $absent = $records->filter(fn (AttendanceRecord $row) => $row->status === AttendanceStatus::Absent)->count();
        $opened = $records->count();
        $attended = $present + $late;

        return [
            'opened' => $opened,
            'present' => $attended,
            'absent' => $absent,
            'late' => $late,
            'percentage' => $opened > 0 ? round(($attended / $opened) * 100, 1) : null,
        ];
    }

    private function resumptionOn(Term $term): ?string
    {
        $next = Term::query()
            ->where('academic_session_id', $term->academic_session_id)
            ->where('term_number', '>', $term->term_number)
            ->orderBy('term_number')
            ->first();

        if ($next?->starts_on) {
            return $next->starts_on->toDateString();
        }

        $sessionEnd = $term->academicSession?->ends_on;
        if ($sessionEnd === null) {
            return null;
        }

        $nextSession = AcademicSession::query()
            ->whereDate('starts_on', '>', $sessionEnd->toDateString())
            ->orderBy('starts_on')
            ->first();

        return $nextSession?->starts_on?->toDateString();
    }

    private function cleanComment(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
