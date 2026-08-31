<?php

namespace Tests\Feature\Assessments;

use App\Enums\AttendanceStatus;
use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use App\Models\AttendanceRecord;
use App\Models\TermSummary;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AssessmentReportSheetTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_results_payload_includes_letterhead_attendance_and_comments(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $admin = $this->admin();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session, [
            'status' => SessionStatus::Active,
            'starts_on' => '2026-09-08',
            'ends_on' => '2026-12-12',
        ]);
        $this->settings([
            'name' => 'Supreme Reagan Schools',
            'motto' => 'Knowledge · Character · Excellence',
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $teacher = $this->userWithRole(RoleSlug::Teacher, ['name' => 'Mrs. Eze']);
        $this->classTeacher($this->staff($teacher), $offering);
        $principal = $this->userWithRole(RoleSlug::Principal, ['name' => 'Mr. Okoro']);
        $this->staff($principal);
        $pupil = $this->student();
        $enrollment = $this->enroll($pupil, $offering);

        $this->actingAs($admin)->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['examination']->id,
            'term_id' => $term->id,
            'score' => 64,
        ])->assertCreated();

        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2026-09-15',
            'status' => AttendanceStatus::Present,
            'marked_by' => $teacher->id,
        ]);
        AttendanceRecord::query()->create([
            'enrollment_id' => $enrollment->id,
            'class_section_offering_id' => $offering->id,
            'marked_on' => '2026-09-16',
            'status' => AttendanceStatus::Absent,
            'marked_by' => $teacher->id,
        ]);

        $this->actingAs($pupil->user)->getJson('/api/v1/results?term_id='.$term->id)
            ->assertOk()
            ->assertJsonPath('data.admission_number', $pupil->admission_number)
            ->assertJsonPath('data.school.name', 'Supreme Reagan Schools')
            ->assertJsonPath('data.school.motto', 'Knowledge · Character · Excellence')
            ->assertJsonPath('data.class_teacher', 'Mrs. Eze')
            ->assertJsonPath('data.principal', 'Mr. Okoro')
            ->assertJsonPath('data.attendance.opened', 2)
            ->assertJsonPath('data.attendance.present', 1)
            ->assertJsonPath('data.attendance.absent', 1)
            ->assertJsonPath('data.comments.class_teacher', null)
            ->assertJsonPath('data.can_comment.class_teacher', false)
            ->assertJsonPath('data.can_comment.principal', false);
    }

    public function test_class_teacher_can_save_a_comment_and_it_survives_a_mark_recalculation(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session, ['status' => SessionStatus::Active]);
        $offering = $this->offering(session: $session);
        $subject = $this->subject();
        $this->subjectOffering($offering, $subject);
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacher), $offering);
        $enrollment = $this->enroll($this->student(), $offering);

        $this->actingAs($this->admin())->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['first_ca']->id,
            'term_id' => $term->id,
            'score' => 10,
        ])->assertCreated();

        $this->actingAs($teacher)->putJson('/api/v1/results/comments', [
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'class_teacher_comment' => 'A steady term. Keep the reading habit.',
            'principal_comment' => 'Should not land.',
        ])->assertForbidden();

        $this->actingAs($teacher)->putJson('/api/v1/results/comments', [
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'class_teacher_comment' => 'A steady term. Keep the reading habit.',
        ])
            ->assertOk()
            ->assertJsonPath('data.comments.class_teacher', 'A steady term. Keep the reading habit.');

        $this->actingAs($this->admin())->postJson('/api/v1/grades', [
            'enrollment_id' => $enrollment->id,
            'subject_id' => $subject->id,
            'assessment_type_id' => $catalogue['types']['examination']->id,
            'term_id' => $term->id,
            'score' => 50,
        ])->assertCreated();

        $this->assertDatabaseHas('term_summaries', [
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'class_teacher_comment' => 'A steady term. Keep the reading habit.',
        ]);

        $this->actingAs($this->admin())->putJson('/api/v1/results/comments', [
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'principal_comment' => 'Promoted on merit.',
        ])
            ->assertOk()
            ->assertJsonPath('data.comments.principal', 'Promoted on merit.')
            ->assertJsonPath('data.comments.class_teacher', 'A steady term. Keep the reading habit.');

        $this->assertSame(1, TermSummary::query()->count());
    }

    public function test_pupil_cannot_write_report_comments(): void
    {
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session, ['status' => SessionStatus::Active]);
        $offering = $this->offering(session: $session);
        $user = $this->userWithRole(RoleSlug::Student);
        $pupil = $this->student($user);
        $enrollment = $this->enroll($pupil, $offering);

        $this->actingAs($user)->putJson('/api/v1/results/comments', [
            'enrollment_id' => $enrollment->id,
            'term_id' => $term->id,
            'class_teacher_comment' => 'I was good.',
        ])->assertForbidden();
    }
}
