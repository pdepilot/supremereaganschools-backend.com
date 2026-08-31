<?php

namespace Tests\Feature\Admissions;

use App\Enums\EnquiryStatus;
use App\Enums\RoleSlug;
use App\Mail\SchoolCircularMail;
use App\Models\ContactEnquiry;
use App\Models\ContactEnquiryReply;
use App\Models\OutboundMail;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalContactDeskTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->settings([
            'name' => 'Supreme Reagan Schools',
            'short_name' => 'SRS',
            'motto' => 'Modeling excellence',
            'email' => 'supremereagansch@gmail.com',
        ]);
    }

    public function test_admin_contact_page_loads_with_backend_hooks(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/contact')
            ->assertOk()
            ->assertSee('data-page="contact"', false)
            ->assertSee('data-contact-list', false)
            ->assertSee('data-contact-letter', false)
            ->assertSee('data-contact-reply-form', false)
            ->assertSee('data-contact-delete', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('portal-contact.js', false)
            ->assertSee('href="/portal/contact"', false)
            ->assertSee('href="/portal/email"', false)
            ->assertDontSee('Mrs. Ngozi Eze', false);

        $js = (string) file_get_contents(public_path('site/JS/portal-contact.js'));
        $this->assertStringContainsString('/api/v1/contact-enquiries', $js);
        $this->assertStringContainsString('/reply', $js);
        $this->assertStringContainsString('DELETE', $js);
        $this->assertStringContainsString('confirmDesk', $js);
        $this->assertStringContainsString('escapeHtml', $js);
    }

    public function test_parents_cannot_open_the_contact_desk(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)
            ->get('/portal/contact')
            ->assertRedirect(route('parent.home'));
    }

    public function test_admin_can_read_reply_and_delete_a_website_letter(): void
    {
        Mail::fake();

        $admin = $this->admin();

        $this->postJson('/api/v1/contact-enquiries', [
            'name' => 'Mrs. Ngozi Eze',
            'phone' => '08031110011',
            'email' => 'ngozi@example.test',
            'subject' => 'Admission enquiry',
            'message' => 'May we visit the nursery wing on Thursday morning?',
        ])->assertCreated();

        $enquiry = ContactEnquiry::query()->first();
        $this->assertNotNull($enquiry);
        $this->assertSame(EnquiryStatus::Urgent, $enquiry->status);

        $this->actingAs($admin)->getJson('/api/v1/contact-enquiries')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.email', 'ngozi@example.test')
            ->assertJsonPath('data.0.replied', false);

        $this->actingAs($admin)->getJson('/api/v1/contact-enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertJsonPath('data.name', 'Mrs. Ngozi Eze')
            ->assertJsonPath('data.status', 'urgent');

        $this->actingAs($admin)->postJson('/api/v1/contact-enquiries/'.$enquiry->id.'/reply', [
            'subject' => 'Re: Admission enquiry',
            'body' => 'You are welcome from 9 a.m. Please come to the school office.',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cleared')
            ->assertJsonPath('data.replied', true)
            ->assertJsonPath('data.replies.0.body', 'You are welcome from 9 a.m. Please come to the school office.');

        Mail::assertSent(SchoolCircularMail::class, 1);
        Mail::assertSent(SchoolCircularMail::class, function (SchoolCircularMail $mail) {
            return $mail->hasTo('ngozi@example.test')
                && $mail->subjectLine === 'Re: Admission enquiry'
                && str_contains($mail->greeting, 'Mrs. Ngozi Eze')
                && str_contains($mail->bodyText, 'You are welcome from 9 a.m.');
        });

        $this->assertDatabaseHas('contact_enquiry_replies', [
            'contact_enquiry_id' => $enquiry->id,
            'user_id' => $admin->id,
        ]);
        $this->assertSame(1, OutboundMail::query()->count());
        $this->assertSame(EnquiryStatus::Cleared, $enquiry->fresh()->status);

        $this->actingAs($admin)->deleteJson('/api/v1/contact-enquiries/'.$enquiry->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('contact_enquiries', ['id' => $enquiry->id]);
        $this->assertSame(0, ContactEnquiryReply::query()->count());
    }

    public function test_teacher_cannot_read_reply_or_delete_contact_letters(): void
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

        $this->actingAs($teacher)->getJson('/api/v1/contact-enquiries')->assertForbidden();
        $this->actingAs($teacher)->getJson('/api/v1/contact-enquiries/'.$enquiry->id)->assertForbidden();
        $this->actingAs($teacher)->postJson('/api/v1/contact-enquiries/'.$enquiry->id.'/reply', [
            'body' => 'Please collect the forms at the office.',
        ])->assertForbidden();
        $this->actingAs($teacher)->deleteJson('/api/v1/contact-enquiries/'.$enquiry->id)->assertForbidden();
    }

    public function test_reply_requires_a_letter_body(): void
    {
        $admin = $this->admin();
        $enquiry = ContactEnquiry::query()->create([
            'name' => 'PTA Secretariat',
            'phone' => '08030000000',
            'email' => 'pta@example.test',
            'subject' => 'General correspondence',
            'message' => 'Hall booking.',
            'status' => EnquiryStatus::Unread,
        ]);

        $this->actingAs($admin)->postJson('/api/v1/contact-enquiries/'.$enquiry->id.'/reply', [
            'subject' => 'Re: Hall',
            'body' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['body']);
    }
}
