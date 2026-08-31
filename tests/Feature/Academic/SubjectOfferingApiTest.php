<?php

namespace Tests\Feature\Academic;

use App\Models\SubjectOffering;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class SubjectOfferingApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_can_create_a_subject_and_duplicates_are_rejected(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->postJson('/api/v1/subjects', [
                'name' => 'Mathematics',
                'code' => 'MTH',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.name', 'Mathematics');

        $this->actingAs($admin)
            ->postJson('/api/v1/subjects', [
                'name' => 'Mathematics',
                'code' => 'MATH',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['name']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/subjects', [
                'name' => 'Further Mathematics',
                'code' => 'MTH',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['code']]);
    }

    public function test_subject_offering_uses_the_normalized_class_session_context(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $subject = $this->subject();

        $this->actingAs($admin)
            ->postJson('/api/v1/subject-offerings', [
                'class_section_offering_id' => $offering->id,
                'subject_id' => $subject->id,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.class_section_offering_id', $offering->id)
            ->assertJsonPath('data.subject_id', $subject->id);

        $this->assertTrue($offering->subjects()->whereKey($subject->id)->exists());
    }

    public function test_duplicate_subject_offerings_for_the_same_context_are_rejected(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $subject = $this->subject();

        SubjectOffering::query()->create([
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($admin)
            ->postJson('/api/v1/subject-offerings', [
                'class_section_offering_id' => $offering->id,
                'subject_id' => $subject->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['subject_id']]);
    }

    public function test_invalid_subject_and_offering_relationships_are_rejected(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $subject = $this->subject();

        $this->actingAs($admin)
            ->postJson('/api/v1/subject-offerings', [
                'class_section_offering_id' => 9999,
                'subject_id' => $subject->id,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['class_section_offering_id']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/subject-offerings', [
                'class_section_offering_id' => $offering->id,
                'subject_id' => 9999,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['subject_id']]);

        $this->actingAs($admin)
            ->postJson('/api/v1/class-section-offerings', [
                'class_section_id' => 9999,
                'academic_session_id' => $offering->academic_session_id,
                'campus_id' => $offering->campus_id,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['class_section_id']]);
    }

    public function test_duplicate_class_section_offerings_for_the_same_session_are_rejected(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();

        $this->actingAs($admin)
            ->postJson('/api/v1/class-section-offerings', [
                'class_section_id' => $offering->class_section_id,
                'academic_session_id' => $offering->academic_session_id,
                'campus_id' => $offering->campus_id,
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['academic_session_id']]);
    }

    public function test_a_subject_in_use_cannot_be_deleted(): void
    {
        $offering = $this->offering();
        $subject = $this->subject();
        SubjectOffering::query()->create([
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/subjects/'.$subject->id)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['subject']]);

        $this->assertDatabaseHas('subjects', ['id' => $subject->id]);
    }

    public function test_an_offering_with_subjects_cannot_be_deleted(): void
    {
        $offering = $this->offering();
        SubjectOffering::query()->create([
            'class_section_offering_id' => $offering->id,
            'subject_id' => $this->subject()->id,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson('/api/v1/class-section-offerings/'.$offering->id)
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['offering']]);
    }
}
