<?php

namespace Tests\Feature\Classroom;

use App\Enums\RoleSlug;
use App\Models\Assignment;
use App\Models\Document;
use App\Models\LearningMaterial;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class ClassroomWorkApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_assignment_list_is_rejected(): void
    {
        $this->getJson('/api/v1/assignments')->assertUnauthorized();
        $this->getJson('/api/v1/learning-materials')->assertUnauthorized();
    }

    public function test_assigned_teacher_can_set_work_and_unrelated_teacher_cannot(): void
    {
        $offering = $this->offering();
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $subjectOffering = $this->subjectOffering($offering, $subject);

        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->subjectTeacher($this->staff($teacher), $subjectOffering);

        $stranger = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($stranger);

        $payload = [
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'title' => 'Exercise 4',
            'instructions' => 'Page 12.',
            'due_on' => '2026-09-10',
        ];

        $this->actingAs($teacher)->postJson('/api/v1/assignments', $payload)
            ->assertCreated()
            ->assertJsonPath('data.title', 'Exercise 4');

        $this->actingAs($stranger)->postJson('/api/v1/assignments', $payload)
            ->assertForbidden();
    }

    public function test_parent_cannot_read_another_childs_assignments(): void
    {
        $home = $this->offering();
        $other = $this->otherOffering($home);
        $subject = $this->subject();
        $this->subjectOffering($home, $subject);
        $this->subjectOffering($other, $subject);

        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $staff = $this->staff($teacher);
        $this->classTeacher($staff, $home);
        $this->classTeacher($staff, $other);

        $ownChild = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $otherChild = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0198']);
        $this->enroll($ownChild, $home);
        $this->enroll($otherChild, $other);

        $parent = $this->userWithRole(RoleSlug::Parent);
        $this->linkGuardian($this->guardian($parent), $ownChild);

        Assignment::query()->create([
            'class_section_offering_id' => $home->id,
            'subject_id' => $subject->id,
            'staff_profile_id' => $staff->id,
            'title' => 'Own class work',
            'due_on' => '2026-09-10',
        ]);
        Assignment::query()->create([
            'class_section_offering_id' => $other->id,
            'subject_id' => $subject->id,
            'staff_profile_id' => $staff->id,
            'title' => 'Other class work',
            'due_on' => '2026-09-10',
        ]);

        $this->actingAs($parent)->getJson('/api/v1/assignments?student_profile_id='.$ownChild->id)
            ->assertOk()
            ->assertJsonFragment(['title' => 'Own class work'])
            ->assertJsonMissing(['title' => 'Other class work']);

        $this->actingAs($parent)->getJson('/api/v1/assignments?student_profile_id='.$otherChild->id)
            ->assertForbidden();
    }

    public function test_material_upload_is_private_and_download_is_scoped(): void
    {
        $disk = Storage::fake('local');

        $offering = $this->offering();
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $subjectOffering = $this->subjectOffering($offering, $subject);

        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->subjectTeacher($this->staff($teacher), $subjectOffering);

        $parent = $this->userWithRole(RoleSlug::Parent);
        $guardian = $this->guardian($parent);
        $child = $this->student();
        $this->linkGuardian($guardian, $child);
        $this->enroll($child, $offering);

        $stranger = $this->userWithRole(RoleSlug::Parent);

        $response = $this->actingAs($teacher)->post('/api/v1/learning-materials', [
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'title' => 'Week 3 notes',
            'file' => UploadedFile::fake()->create('notes.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json']);

        $response->assertCreated()->assertJsonPath('data.title', 'Week 3 notes');

        $document = Document::query()->first();
        $this->assertNotNull($document);
        $this->assertSame('local', $document->disk);
        $disk->assertExists($document->path);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/documents/'.$document->id.'/download')->assertUnauthorized();

        $this->actingAs($stranger)->getJson('/api/v1/documents/'.$document->id.'/download')
            ->assertForbidden();

        $this->actingAs($parent)->get('/api/v1/documents/'.$document->id.'/download')
            ->assertOk();

        $this->actingAs($teacher)->get('/api/v1/documents/'.$document->id.'/download')
            ->assertOk();
    }

    public function test_assignment_validation_uses_the_envelope(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacher);

        $this->actingAs($teacher)->postJson('/api/v1/assignments', [
            'title' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['class_section_offering_id', 'subject_id', 'title', 'due_on']);
    }

    public function test_enrolled_pupil_can_hand_in_work_and_resubmit(): void
    {
        $disk = Storage::fake('local');
        $scene = $this->classWithAssignment();

        $this->actingAs($scene['pupil'])->post('/api/v1/assignments/'.$scene['assignment']->id.'/submissions', [
            'notes' => 'Page 12 done.',
            'file' => UploadedFile::fake()->create('work.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Exercise 4')
            ->assertJsonPath('data.can_submit', true)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.submission.original_name', 'work.pdf')
            ->assertJsonPath('data.submission.notes', 'Page 12 done.');

        $this->assertSame(1, \App\Models\AssignmentSubmission::query()->count());
        $document = Document::query()->where('type', 'assignment_submission')->first();
        $this->assertNotNull($document);
        $disk->assertExists($document->path);

        $this->actingAs($scene['pupil'])->getJson('/api/v1/assignments')
            ->assertOk()
            ->assertJsonPath('data.0.submission.original_name', 'work.pdf')
            ->assertJsonPath('data.0.can_submit', true);

        $this->actingAs($scene['pupil'])->post('/api/v1/assignments/'.$scene['assignment']->id.'/submissions', [
            'notes' => 'Revised.',
            'file' => UploadedFile::fake()->create('revised.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.submission.original_name', 'revised.pdf')
            ->assertJsonPath('data.submission.notes', 'Revised.');

        $this->assertSame(1, \App\Models\AssignmentSubmission::query()->count());
        $this->assertSame(1, Document::query()->where('type', 'assignment_submission')->count());
    }

    public function test_parent_and_classmates_cannot_submit_or_read_another_pupils_paper(): void
    {
        $disk = Storage::fake('local');
        $scene = $this->classWithAssignment();

        $classmateUser = $this->userWithRole(RoleSlug::Student);
        $classmate = $this->student($classmateUser, [
            'surname' => 'Eze',
            'first_name' => 'Ikenna',
            'admission_number' => 'SRS/2025/0888',
        ]);
        $this->enroll($classmate, $scene['offering']);

        $parent = $this->userWithRole(RoleSlug::Parent);
        $this->linkGuardian($this->guardian($parent), $scene['profile']);

        $otherParent = $this->userWithRole(RoleSlug::Parent);
        $this->linkGuardian($this->guardian($otherParent, ['phone' => '08030000099', 'email' => 'other-parent@example.test']), $classmate);

        $this->actingAs($parent)->post('/api/v1/assignments/'.$scene['assignment']->id.'/submissions', [
            'notes' => 'I will write it.',
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs($scene['pupil'])->post('/api/v1/assignments/'.$scene['assignment']->id.'/submissions', [
            'file' => UploadedFile::fake()->create('work.pdf', 40, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();

        $document = Document::query()->where('type', 'assignment_submission')->first();
        $this->assertNotNull($document);
        $disk->assertExists($document->path);

        $this->actingAs($classmateUser)->get('/api/v1/documents/'.$document->id.'/download')
            ->assertForbidden();

        $this->actingAs($otherParent)->get('/api/v1/documents/'.$document->id.'/download')
            ->assertForbidden();

        $this->actingAs($parent)->get('/api/v1/documents/'.$document->id.'/download')
            ->assertOk();

        $this->actingAs($scene['teacher'])->get('/api/v1/documents/'.$document->id.'/download')
            ->assertOk();

        $this->actingAs($parent)->getJson('/api/v1/assignments?student_profile_id='.$scene['profile']->id)
            ->assertOk()
            ->assertJsonPath('data.0.can_submit', false)
            ->assertJsonPath('data.0.submission.original_name', 'work.pdf');
    }

    public function test_unenrolled_pupil_cannot_hand_in_and_empty_work_is_rejected(): void
    {
        $scene = $this->classWithAssignment();
        $outsider = $this->userWithRole(RoleSlug::Student);
        $this->student($outsider, ['admission_number' => 'SRS/2025/0777']);

        $this->actingAs($outsider)->post('/api/v1/assignments/'.$scene['assignment']->id.'/submissions', [
            'notes' => 'Guessing.',
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->actingAs($scene['pupil'])->postJson('/api/v1/assignments/'.$scene['assignment']->id.'/submissions', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file']);
    }

    public function test_teacher_can_open_the_class_inbox_and_strangers_cannot(): void
    {
        Storage::fake('local');
        $scene = $this->classWithAssignment();
        $missing = $this->userWithRole(RoleSlug::Student);
        $this->enroll($this->student($missing, [
            'surname' => 'Okeke',
            'first_name' => 'Ada',
            'admission_number' => 'SRS/2025/0555',
        ]), $scene['offering']);

        $this->actingAs($scene['pupil'])->post('/api/v1/assignments/'.$scene['assignment']->id.'/submissions', [
            'notes' => 'Finished.',
        ], ['Accept' => 'application/json'])->assertOk();

        $this->actingAs($scene['teacher'])->getJson('/api/v1/assignments/'.$scene['assignment']->id.'/submissions')
            ->assertOk()
            ->assertJsonFragment(['student_name' => 'Okafor Chiamaka', 'submitted' => true])
            ->assertJsonFragment(['student_name' => 'Okeke Ada', 'submitted' => false]);

        $this->actingAs($scene['pupil'])->getJson('/api/v1/assignments/'.$scene['assignment']->id.'/submissions')
            ->assertForbidden();

        $stranger = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($stranger);
        $this->actingAs($stranger)->getJson('/api/v1/assignments/'.$scene['assignment']->id.'/submissions')
            ->assertForbidden();
    }

    public function test_late_hand_in_is_marked_late(): void
    {
        $scene = $this->classWithAssignment();
        $scene['assignment']->update(['due_on' => '2026-08-01']);

        $this->actingAs($scene['pupil'])->post('/api/v1/assignments/'.$scene['assignment']->id.'/submissions', [
            'notes' => 'Sorry it is late.',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'late')
            ->assertJsonPath('data.submission.late', true);
    }

    /**
     * @return array{
     *     offering: \App\Models\ClassSectionOffering,
     *     teacher: \App\Models\User,
     *     pupil: \App\Models\User,
     *     profile: \App\Models\StudentProfile,
     *     assignment: Assignment
     * }
     */
    private function classWithAssignment(): array
    {
        $offering = $this->offering();
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $subjectOffering = $this->subjectOffering($offering, $subject);

        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $staff = $this->staff($teacher);
        $this->subjectTeacher($staff, $subjectOffering);

        $pupil = $this->userWithRole(RoleSlug::Student);
        $profile = $this->student($pupil);
        $this->enroll($profile, $offering);

        $assignment = Assignment::query()->create([
            'class_section_offering_id' => $offering->id,
            'subject_id' => $subject->id,
            'staff_profile_id' => $staff->id,
            'title' => 'Exercise 4',
            'instructions' => 'Page 12.',
            'due_on' => '2026-09-10',
        ]);

        return [
            'offering' => $offering,
            'teacher' => $teacher,
            'pupil' => $pupil,
            'profile' => $profile,
            'assignment' => $assignment,
        ];
    }
}
