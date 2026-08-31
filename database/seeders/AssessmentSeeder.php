<?php

namespace Database\Seeders;

use App\Enums\AssessmentKind;
use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\GradeScale;
use App\Models\StudentProfile;
use App\Models\Subject;
use App\Models\Term;
use App\Models\User;
use App\Services\AssessmentService;
use Illuminate\Database\Seeder;

class AssessmentSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['kind' => AssessmentKind::FirstCa, 'name' => 'First CA', 'max_score' => 15, 'sort_order' => 1],
            ['kind' => AssessmentKind::SecondCa, 'name' => 'Second CA', 'max_score' => 15, 'sort_order' => 2],
            ['kind' => AssessmentKind::Examination, 'name' => 'Examination', 'max_score' => 70, 'sort_order' => 3],
        ] as $row) {
            AssessmentType::query()->updateOrCreate(
                ['kind' => $row['kind']],
                [
                    'name' => $row['name'],
                    'max_score' => $row['max_score'],
                    'sort_order' => $row['sort_order'],
                    'is_active' => true,
                ],
            );
        }

        foreach ([
            ['min_score' => 75, 'max_score' => 100, 'grade' => 'A', 'remark' => 'Excellent', 'sort_order' => 1],
            ['min_score' => 65, 'max_score' => 74.99, 'grade' => 'B', 'remark' => 'Very Good', 'sort_order' => 2],
            ['min_score' => 50, 'max_score' => 64.99, 'grade' => 'C', 'remark' => 'Good', 'sort_order' => 3],
            ['min_score' => 40, 'max_score' => 49.99, 'grade' => 'D', 'remark' => 'Fair', 'sort_order' => 4],
            ['min_score' => 0, 'max_score' => 39.99, 'grade' => 'F', 'remark' => 'Needs Support', 'sort_order' => 5],
        ] as $row) {
            GradeScale::query()->updateOrCreate(
                ['grade' => $row['grade']],
                $row,
            );
        }

        $term = Term::query()
            ->where('name', 'First Term')
            ->whereHas('academicSession', fn ($query) => $query->where('name', '2025/2026'))
            ->first();

        if ($term === null) {
            return;
        }

        $actorId = User::query()->where('email', 'eze@supremereaganschools.test')->value('id')
            ?? LocalAdminSeeder::user()?->id;

        $marks = [
            'Mathematics' => [14, 14, 66],
            'English Language' => [12, 13, 58],
            'Basic Science' => [12, 12, 55],
            'Social Studies' => [13, 13, 55],
            'Computer Studies' => [14, 15, 62],
            'Basic Technology' => [11, 12, 50],
            'Civic Education' => [12, 12, 52],
        ];

        foreach ($marks as $subjectName => [$ca1, $ca2, $exam]) {
            $this->score('SRS/2025/0142', $subjectName, $term, [
                AssessmentKind::FirstCa->value => $ca1,
                AssessmentKind::SecondCa->value => $ca2,
                AssessmentKind::Examination->value => $exam,
            ], $actorId);
        }

        $enrollment = StudentProfile::query()
            ->where('admission_number', 'SRS/2025/0142')
            ->first()
            ?->enrollments()
            ->orderByDesc('enrolled_on')
            ->first();

        if ($enrollment === null) {
            return;
        }

        $assessments = app(AssessmentService::class);
        foreach (array_keys($marks) as $subjectName) {
            $subjectId = Subject::query()->where('name', $subjectName)->value('id');
            if ($subjectId) {
                $assessments->recalculateSubject((int) $enrollment->class_section_offering_id, (int) $subjectId, $term->id);
            }
        }
        $assessments->recalculateOfferingSummaries((int) $enrollment->class_section_offering_id, $term->id);
    }

    /**
     * @param  array<string, int|float>  $scores
     */
    private function score(string $admissionNumber, string $subjectName, Term $term, array $scores, ?int $actorId): void
    {
        $enrollment = StudentProfile::query()
            ->where('admission_number', $admissionNumber)
            ->first()
            ?->enrollments()
            ->orderByDesc('enrolled_on')
            ->first();

        $subjectId = Subject::query()->where('name', $subjectName)->value('id');

        if ($enrollment === null || $subjectId === null) {
            return;
        }

        foreach ($scores as $kind => $value) {
            $typeId = AssessmentType::query()->where('kind', $kind)->value('id');

            if ($typeId === null) {
                continue;
            }

            AssessmentScore::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'term_id' => $term->id,
                    'subject_id' => $subjectId,
                    'assessment_type_id' => $typeId,
                ],
                [
                    'score' => $value,
                    'entered_by' => $actorId,
                ],
            );
        }
    }
}
