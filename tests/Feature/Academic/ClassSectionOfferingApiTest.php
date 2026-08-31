<?php

namespace Tests\Feature\Academic;

use App\Enums\EnrollmentStatus;
use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class ClassSectionOfferingApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_list_forms_with_teacher_and_active_roll(): void
    {
        $offering = $this->offering();
        $teacher = $this->staff($this->userWithRole(RoleSlug::Teacher, ['name' => 'Mrs. Eze']));
        $this->classTeacher($teacher, $offering);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0142',
        ]), $offering);
        $this->enroll($this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0198',
        ]), $offering, ['status' => EnrollmentStatus::Withdrawn]);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/class-section-offerings')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.form', 'JSS 1 A')
            ->assertJsonPath('data.0.level_name', 'Junior Secondary')
            ->assertJsonPath('data.0.level_slug', 'jss')
            ->assertJsonPath('data.0.class_teacher', 'Mrs. Eze')
            ->assertJsonPath('data.0.enrollment_count', 1)
            ->assertJsonPath('data.0.capacity', 30)
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.subjects', []);
    }

    public function test_forms_include_offered_subjects(): void
    {
        $offering = $this->offering();
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $subjectOffering = $this->subjectOffering($offering, $subject);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/class-section-offerings')
            ->assertOk()
            ->assertJsonPath('data.0.id', $offering->id)
            ->assertJsonPath('data.0.subjects.0.id', $subject->id)
            ->assertJsonPath('data.0.subjects.0.offering_id', $subjectOffering->id)
            ->assertJsonPath('data.0.subjects.0.name', 'Mathematics')
            ->assertJsonPath('data.0.subjects.0.code', 'MTH');
    }

    public function test_admin_can_open_a_form_and_appoint_a_master(): void
    {
        $session = $this->academicSession();
        $campus = $this->campus();
        $section = $this->section();
        $staff = $this->staff($this->userWithRole(RoleSlug::Teacher, ['name' => 'Mr. Okoro']));

        $this->actingAs($this->admin())
            ->postJson('/api/v1/class-section-offerings', [
                'class_section_id' => $section->id,
                'academic_session_id' => $session->id,
                'campus_id' => $campus->id,
                'capacity' => 28,
                'staff_profile_id' => $staff->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.form', 'JSS 1 A')
            ->assertJsonPath('data.capacity', 28)
            ->assertJsonPath('data.class_teacher', 'Mr. Okoro')
            ->assertJsonPath('data.enrollment_count', 0);
    }

    public function test_admin_can_open_a_form_with_subjects(): void
    {
        $session = $this->academicSession();
        $campus = $this->campus();
        $section = $this->section();
        $subject = $this->subject(['name' => 'Further Mathematics', 'code' => 'FMTH']);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/class-section-offerings', [
                'class_section_id' => $section->id,
                'academic_session_id' => $session->id,
                'campus_id' => $campus->id,
                'capacity' => 28,
                'subject_ids' => [$subject->id],
            ])
            ->assertCreated()
            ->assertJsonPath('data.form', 'JSS 1 A')
            ->assertJsonPath('data.subjects.0.id', $subject->id)
            ->assertJsonPath('data.subjects.0.name', 'Further Mathematics');
    }

    public function test_admin_can_close_and_reopen_a_form(): void
    {
        $offering = $this->offering();
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/v1/class-section-offerings/'.$offering->id, [
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($admin)
            ->putJson('/api/v1/class-section-offerings/'.$offering->id, [
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    public function test_a_form_with_pupils_cannot_be_deleted(): void
    {
        $offering = $this->offering();
        $this->enroll($this->student(), $offering);

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/class-section-offerings/'.$offering->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offering');

        $this->assertDatabaseHas('class_section_offerings', ['id' => $offering->id]);
    }

    public function test_an_empty_form_can_be_removed(): void
    {
        $offering = $this->offering();

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/class-section-offerings/'.$offering->id)
            ->assertOk()
            ->assertJsonPath('message', 'Class offering deleted.');

        $this->assertDatabaseMissing('class_section_offerings', ['id' => $offering->id]);
    }

    public function test_offerings_can_be_filtered_by_session_and_activity(): void
    {
        $live = $this->offering(null, $this->academicSession(['status' => SessionStatus::Active]));
        $closed = $this->offering(
            $this->section($this->schoolClass($live->classSection->schoolClass->level, [
                'name' => 'JSS 2',
                'short_code' => 'J2',
            ]), ['arm' => 'B', 'name' => 'JSS 2 B']),
            $live->academicSession,
            $live->campus,
            ['is_active' => false],
        );

        $this->actingAs($this->admin())
            ->getJson('/api/v1/class-section-offerings?academic_session_id='.$live->academic_session_id.'&is_active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $live->id);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/class-section-offerings?is_active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $closed->id);
    }
}
