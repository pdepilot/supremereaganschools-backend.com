<?php

namespace Tests\Feature\Admissions;

use App\Enums\ApplicationStatus;
use App\Enums\EnquiryStatus;
use App\Enums\RoleSlug;
use App\Models\AdmissionApplication;
use App\Models\ContactEnquiry;
use App\Models\Document;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PublicAdmissionsTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_guest_can_submit_a_contact_enquiry(): void
    {
        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Mrs. Ngozi Eze',
            'phone' => '08031110011',
            'email' => 'ngozi@example.test',
            'subject' => 'Request a campus visit',
            'message' => 'I would like to visit before first-term admissions close.',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'urgent')
            ->assertJsonPath('data.name', 'Mrs. Ngozi Eze');

        $this->assertDatabaseHas('contact_enquiries', [
            'email' => 'ngozi@example.test',
            'status' => EnquiryStatus::Urgent->value,
        ]);
    }

    public function test_general_correspondence_is_unread_not_urgent(): void
    {
        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'PTA Secretariat',
            'phone' => '08030000000',
            'email' => 'pta@example.test',
            'subject' => 'General correspondence',
            'message' => 'Confirming the hall for Thursday’s briefing.',
        ])->assertCreated()->assertJsonPath('data.status', 'unread');
    }

    public function test_guest_can_submit_an_application_with_private_documents(): void
    {
        $local = Storage::fake('local');
        $public = Storage::fake('public');
        $this->level(['name' => 'Junior Secondary', 'slug' => 'jss']);
        $this->academicSession(['name' => '2025/2026']);

        $photo = UploadedFile::fake()->image('passport.jpg', 200, 200);

        $this->post('/api/v1/admission-applications', array_merge($this->applicationPayload(), [
            'passportPhoto' => $photo,
        ]), ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.reference', 'ADM-0001')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.level_name', 'Junior Secondary');

        $application = AdmissionApplication::query()->first();
        $this->assertNotNull($application);
        $this->assertSame(ApplicationStatus::Submitted, $application->status);
        $this->assertDatabaseCount('documents', 1);

        $document = Document::query()->first();
        $this->assertSame('local', $document->disk);
        $this->assertFalse(str_starts_with($document->path, 'public/'));
        $local->assertExists($document->path);
        $public->assertMissing($document->path);
    }

    public function test_public_submit_cannot_mass_assign_status_or_reference(): void
    {
        $this->postJson('/api/v1/admission-applications', array_merge($this->applicationPayload(), [
            'status' => 'admitted',
            'reference' => 'ADM-9999',
            'student_profile_id' => 1,
        ]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.reference', 'ADM-0001')
            ->assertJsonPath('data.student_profile_id', null);
    }

    public function test_missing_fields_return_the_error_envelope(): void
    {
        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Anon',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['phone', 'email', 'subject', 'message']);
    }

    /**
     * @return array<string, mixed>
     */
    private function applicationPayload(): array
    {
        return [
            'session' => '2025/2026',
            'level' => 'Secondary',
            'classApplied' => 'JSS 1',
            'entryTerm' => 'First Term',
            'surname' => 'Eze',
            'firstName' => 'Ifeanyi',
            'otherNames' => '',
            'gender' => 'Female',
            'dob' => '2014-03-12',
            'nationality' => 'Nigerian',
            'stateOfOrigin' => 'Imo',
            'lga' => 'Owerri North',
            'homeAddress' => '15 Spibat Road, Owerri',
            'parentName' => 'Mrs. Ngozi Eze',
            'relationship' => 'Mother',
            'parentPhone' => '08031110011',
            'parentEmail' => 'ngozi@example.test',
        ];
    }
}
