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

class AdmissionsAuthorizationTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_requests_cannot_read_the_inbox(): void
    {
        $this->getJson('/api/v1/inbox')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_teacher_cannot_read_or_update_inbound_mail(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $enquiry = ContactEnquiry::query()->create([
            'name' => 'Mrs. Amaka',
            'phone' => '08030000001',
            'email' => 'amaka@example.test',
            'subject' => 'General correspondence',
            'message' => 'Nursery 2 excursion consent forms.',
            'status' => EnquiryStatus::Unread,
        ]);

        $this->actingAs($teacher)->getJson('/api/v1/inbox')->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/contact-enquiries')->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/admission-applications')->assertForbidden();
        $this->actingAs($teacher)->putJson('/api/v1/contact-enquiries/'.$enquiry->id, [
            'status' => 'cleared',
        ])->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/v1/contact-enquiries/'.$enquiry->id.'/reply', [
            'body' => 'Please collect the forms at the office.',
        ])->assertForbidden();
        $this->actingAs($teacher)->deleteJson('/api/v1/contact-enquiries/'.$enquiry->id)->assertForbidden();
    }

    public function test_admin_can_triage_the_chute_and_update_status(): void
    {
        $admin = $this->admin();

        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Mrs. Ngozi Eze',
            'phone' => '08031110011',
            'email' => 'ngozi@example.test',
            'subject' => 'Admission enquiry',
            'message' => 'Campus visit for her son.',
        ])->assertCreated();

        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'PTA Secretariat',
            'phone' => '08030000000',
            'email' => 'pta@example.test',
            'subject' => 'General correspondence',
            'message' => 'Hall booking.',
        ])->assertCreated();

        $this->postJson('/api/v1/admission-applications', [
            'session' => '2025/2026',
            'level' => 'Primary',
            'classApplied' => 'Primary 4',
            'entryTerm' => 'First Term',
            'surname' => 'Okoro',
            'firstName' => 'Daniel',
            'gender' => 'Male',
            'dob' => '2016-01-20',
            'nationality' => 'Nigerian',
            'stateOfOrigin' => 'Imo',
            'homeAddress' => 'Owerri',
            'parentName' => 'Mr. Okoro',
            'relationship' => 'Father',
            'parentPhone' => '08031110002',
            'parentEmail' => 'okoro@example.test',
        ])->assertCreated();

        $this->actingAs($admin)->getJson('/api/v1/inbox')
            ->assertOk()
            ->assertJsonPath('data.summary.urgent', 2)
            ->assertJsonPath('data.summary.watch', 1);

        $enquiry = ContactEnquiry::query()->where('subject', 'Admission enquiry')->first();
        $application = AdmissionApplication::query()->first();

        $this->actingAs($admin)->postJson('/api/v1/inbox/open', [
            'kind' => 'enquiry',
            'id' => $enquiry->id,
        ])->assertOk();

        $this->actingAs($admin)->postJson('/api/v1/inbox/open', [
            'kind' => 'application',
            'id' => $application->id,
        ])->assertOk();

        $this->assertSame(ApplicationStatus::UnderReview, $application->fresh()->status);

        $this->actingAs($admin)->putJson('/api/v1/admission-applications/'.$application->id, [
            'status' => 'offered',
        ])->assertOk()->assertJsonPath('data.status', 'offered');

        $this->actingAs($admin)->postJson('/api/v1/inbox/clear-urgent')
            ->assertOk();

        $this->assertSame(EnquiryStatus::Cleared, $enquiry->fresh()->status);
    }

    public function test_only_admins_can_download_private_documents(): void
    {
        Storage::fake('local');
        $admin = $this->admin();
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $photo = UploadedFile::fake()->image('passport.jpg');
        $this->post('/api/v1/admission-applications', [
            'session' => '2025/2026',
            'level' => 'Nursery',
            'classApplied' => 'Nursery 2',
            'entryTerm' => 'First Term',
            'surname' => 'Nwosu',
            'firstName' => 'Adaeze',
            'gender' => 'Female',
            'dob' => '2021-06-01',
            'nationality' => 'Nigerian',
            'stateOfOrigin' => 'Imo',
            'homeAddress' => 'Owerri',
            'parentName' => 'Mr. Nwosu',
            'relationship' => 'Father',
            'parentPhone' => '08031110003',
            'parentEmail' => 'nwosu@example.test',
            'passportPhoto' => $photo,
        ], ['Accept' => 'application/json'])->assertCreated();

        $document = Document::query()->first();

        $this->getJson('/api/v1/documents/'.$document->id.'/download')
            ->assertUnauthorized();

        $this->actingAs($teacher)->getJson('/api/v1/documents/'.$document->id.'/download')
            ->assertForbidden();

        $this->actingAs($admin)->get('/api/v1/documents/'.$document->id.'/download')
            ->assertOk();
    }

    public function test_parent_cannot_list_applications(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)->getJson('/api/v1/admission-applications')
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }
}
