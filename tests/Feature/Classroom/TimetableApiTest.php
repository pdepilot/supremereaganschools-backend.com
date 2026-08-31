<?php

namespace Tests\Feature\Classroom;

use App\Enums\RoleSlug;
use App\Models\TimetableSlot;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class TimetableApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_timetable_is_rejected(): void
    {
        $this->getJson('/api/v1/timetable')->assertUnauthorized();
    }

    public function test_admin_can_create_a_slot_and_the_unique_time_is_enforced(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $staff = $this->staff();

        $this->actingAs($admin)->postJson('/api/v1/timetable', [
            'class_section_offering_id' => $offering->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
            'subject_id' => $subject->id,
            'staff_profile_id' => $staff->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.subject_name', 'Mathematics');

        $this->actingAs($admin)->postJson('/api/v1/timetable', [
            'class_section_offering_id' => $offering->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
            'label' => 'Break',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_database_unique_constraint_blocks_duplicate_slots(): void
    {
        $offering = $this->offering();

        TimetableSlot::query()->create([
            'class_section_offering_id' => $offering->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
            'label' => 'Assembly',
        ]);

        $this->expectException(QueryException::class);

        TimetableSlot::query()->create([
            'class_section_offering_id' => $offering->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
            'label' => 'Break',
        ]);
    }

    public function test_teacher_cannot_write_the_timetable(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacher), $offering);

        $this->actingAs($teacher)->postJson('/api/v1/timetable', [
            'class_section_offering_id' => $offering->id,
            'day_of_week' => 2,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
            'label' => 'Break',
        ])->assertForbidden();
    }

    public function test_parent_sees_own_class_only(): void
    {
        $home = $this->offering();
        $other = $this->otherOffering($home);
        $parent = $this->userWithRole(RoleSlug::Parent);
        $guardian = $this->guardian($parent);
        $child = $this->student();
        $this->linkGuardian($guardian, $child);
        $this->enroll($child, $home);

        TimetableSlot::query()->create([
            'class_section_offering_id' => $home->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
            'label' => 'Assembly',
        ]);
        TimetableSlot::query()->create([
            'class_section_offering_id' => $other->id,
            'day_of_week' => 1,
            'starts_at' => '08:00',
            'ends_at' => '08:40',
            'label' => 'Other house',
        ]);

        $this->actingAs($parent)->getJson('/api/v1/timetable?class_section_offering_id='.$home->id)
            ->assertOk()
            ->assertJsonFragment(['label' => 'Assembly']);

        $this->actingAs($parent)->getJson('/api/v1/timetable?class_section_offering_id='.$other->id)
            ->assertForbidden();
    }

    public function test_lesson_must_end_after_it_starts(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/timetable', [
            'class_section_offering_id' => $this->offering()->id,
            'day_of_week' => 1,
            'starts_at' => '09:00',
            'ends_at' => '08:00',
            'label' => 'Backwards',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('ends_at');
    }

    public function test_admin_can_read_update_and_remove_a_slot(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $teacher = $this->staff($this->userWithRole(RoleSlug::Teacher, ['name' => 'Mrs. Eze']));
        $this->classTeacher($teacher, $offering);
        $subject = $this->subject(['name' => 'Civic', 'code' => 'CIV']);
        $this->subjectOffering($offering, $subject);

        $created = $this->actingAs($admin)->postJson('/api/v1/timetable', [
            'class_section_offering_id' => $offering->id,
            'day_of_week' => 3,
            'starts_at' => '11:00',
            'ends_at' => '11:40',
            'subject_id' => $subject->id,
            'staff_profile_id' => $teacher->id,
        ])->assertCreated()->json('data');

        $this->actingAs($admin)
            ->getJson('/api/v1/timetable?class_section_offering_id='.$offering->id)
            ->assertOk()
            ->assertJsonPath('data.form', 'JSS 1 A')
            ->assertJsonPath('data.class_teacher', 'Mrs. Eze')
            ->assertJsonPath('data.period_count', 1)
            ->assertJsonPath('data.first_bell', '11:00')
            ->assertJsonPath('data.last_bell', '11:40')
            ->assertJsonPath('data.mapped_forms', 1)
            ->assertJsonPath('data.can_edit', true);

        $this->actingAs($admin)->putJson('/api/v1/timetable/'.$created['id'], [
            'label' => 'Break',
            'subject_id' => null,
            'staff_profile_id' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.label', 'Break')
            ->assertJsonPath('data.subject_name', null);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/timetable/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('message', 'Timetable slot removed.');

        $this->assertDatabaseMissing('timetable_slots', ['id' => $created['id']]);
    }
}
