<?php

namespace Tests\Feature\Mail;

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Mail\SchoolCircularMail;
use App\Models\OutboundMail;
use Database\Seeders\EmailTemplateSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class EmailCenterTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(EmailTemplateSeeder::class);
        $this->settings([
            'name' => 'Supreme Reagan Schools',
            'short_name' => 'SRS',
            'motto' => 'Modeling excellence',
            'email' => 'supremereagansch@gmail.com',
            'admissions_email' => 'supremereagansch@gmail.com',
            'phone' => '09065641343',
            'whatsapp' => '2349065641343',
            'address' => '15 Spibat Road, Amakohia-Akwakuma, Owerri',
            'city' => 'Owerri',
            'state' => 'Imo',
            'office_opens_at' => '08:00',
            'office_closes_at' => '16:00',
            'founded_on' => '2010-09-13',
            'logo_path' => '/site/Image/logo_main.png',
        ]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/email-center')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_parents_cannot_open_the_email_centre(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)->getJson('/api/v1/email-center')
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($parent)->getJson('/api/v1/email-center/people')
            ->assertForbidden();

        $this->actingAs($parent)
            ->get('/portal/email')
            ->assertRedirect(route('parent.home'));
    }

    public function test_admin_page_loads_with_backend_hooks(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/email')
            ->assertOk()
            ->assertSee('data-page="email"', false)
            ->assertSee('data-email-form', false)
            ->assertSee('data-email-templates', false)
            ->assertSee('data-email-people', false)
            ->assertSee('data-email-outbox', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('portal-email.js', false)
            ->assertSee('href="/portal/email"', false)
            ->assertDontSee('NOTE-044', false);

        $js = (string) file_get_contents(public_path('site/JS/portal-email.js'));
        $this->assertStringContainsString('/api/v1/email-center/send', $js);
        $this->assertStringContainsString('/api/v1/email-center/preview', $js);
        $this->assertStringContainsString('/api/v1/email-center/templates', $js);
        $this->assertStringContainsString('/api/v1/email-center/people', $js);
        $this->assertStringContainsString('user_ids', $js);
    }

    public function test_admin_can_list_templates_and_preview_a_letter(): void
    {
        $admin = $this->admin();
        $this->userWithRole(RoleSlug::Parent, ['name' => 'Chioma Okafor']);

        $this->actingAs($admin)->getJson('/api/v1/email-center/templates')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['slug' => 'fee-reminder'])
            ->assertJsonFragment(['name' => 'Welcome to the school']);

        $template = $this->actingAs($admin)->getJson('/api/v1/email-center/templates')->json('data');
        $fee = collect($template)->firstWhere('slug', 'fee-reminder');

        $preview = $this->actingAs($admin)->postJson('/api/v1/email-center/preview', [
            'template_id' => $fee['id'],
            'subject' => $fee['subject'],
            'body' => $fee['body'],
            'audience' => 'parents',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.recipient_count', 1)
            ->assertJsonPath('data.sample_name', 'Chioma Okafor');

        $html = (string) $preview->json('data.html');
        $this->assertStringContainsString('Supreme Reagan Schools', $html);
        $this->assertStringContainsString('Dear Chioma Okafor', $html);
        $this->assertStringContainsString('Official circular', $html);
        $this->assertStringContainsString('15 Spibat Road', $html);
        $this->assertStringContainsString('Owerri, Imo State, Nigeria', $html);
        $this->assertStringContainsString('09065641343', $html);
        $this->assertStringContainsString('Monday – Friday', $html);
        $this->assertStringContainsString('Supreme Reagan Schools crest', $html);
        $this->assertTrue(
            str_contains($html, 'data:image/') || str_contains($html, 'cid:') || str_contains($html, 'logo_main.png'),
            'The school crest should be present in the letter.',
        );
        $this->assertStringNotContainsString('localhost', $html);
    }

    public function test_school_letters_do_not_advertise_localhost_urls(): void
    {
        config(['app.url' => 'http://localhost:8000']);
        $this->settings([
            'website' => 'http://localhost:8000',
        ]);

        $admin = $this->admin();
        $this->userWithRole(RoleSlug::Parent, ['name' => 'Chioma Okafor']);

        $html = (string) $this->actingAs($admin)->postJson('/api/v1/email-center/preview', [
            'subject' => 'A note from the office',
            'body' => 'Please call at the school office.',
            'audience' => 'parents',
        ])->assertOk()->json('data.html');

        $this->assertStringNotContainsString('localhost', $html);
        $this->assertStringNotContainsString('127.0.0.1', $html);
    }

    public function test_admin_can_send_a_template_to_parents(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $parent = $this->userWithRole(RoleSlug::Parent, [
            'name' => 'Ada Obi',
            'email' => 'ada.obi@example.test',
        ]);
        $this->userWithRole(RoleSlug::Parent, [
            'name' => 'Quiet House',
            'email' => 'quiet@example.test',
            'status' => UserStatus::Inactive,
        ]);
        $this->userWithRole(RoleSlug::Teacher, ['email' => 'master@example.test']);

        $template = collect($this->actingAs($admin)->getJson('/api/v1/email-center/templates')->json('data'))
            ->firstWhere('slug', 'welcome');

        $this->actingAs($admin)->postJson('/api/v1/email-center/send', [
            'template_id' => $template['id'],
            'subject' => $template['subject'],
            'body' => $template['body'],
            'audience' => 'parents',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonPath('data.sent_count', 1)
            ->assertJsonPath('data.recipient_count', 1);

        Mail::assertSent(SchoolCircularMail::class, 1);
        Mail::assertSent(SchoolCircularMail::class, function (SchoolCircularMail $mail) use ($parent) {
            return $mail->hasTo($parent->email)
                && $mail->subjectLine === 'Welcome to Supreme Reagan Schools'
                && str_contains($mail->greeting, 'Ada Obi')
                && str_contains($mail->bodyText, 'Supreme Reagan Schools');
        });

        $this->assertSame(1, OutboundMail::query()->count());
        Mail::assertNotSent(SchoolCircularMail::class, fn (SchoolCircularMail $mail) => $mail->hasTo('master@example.test'));
        Mail::assertNotSent(SchoolCircularMail::class, fn (SchoolCircularMail $mail) => $mail->hasTo('quiet@example.test'));
    }

    public function test_named_mailboxes_can_be_dispatched_without_a_template(): void
    {
        Mail::fake();

        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/email-center/send', [
            'subject' => 'Sports day',
            'body' => 'Bring the house kit on Friday.',
            'audience' => 'custom',
            'recipients' => "house@example.test\nnot-an-email, second@example.test",
        ])
            ->assertCreated()
            ->assertJsonPath('data.sent_count', 2);

        Mail::assertSent(SchoolCircularMail::class, 2);
        Mail::assertSent(SchoolCircularMail::class, fn (SchoolCircularMail $mail) => $mail->hasTo('house@example.test'));
        Mail::assertSent(SchoolCircularMail::class, fn (SchoolCircularMail $mail) => $mail->hasTo('second@example.test'));
    }

    public function test_validation_errors_use_the_envelope(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/email-center/send', [
            'subject' => '',
            'audience' => 'not-a-lane',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['subject', 'body', 'audience']);
    }

    public function test_admin_can_send_to_one_or_several_people_on_the_books(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $parent = $this->userWithRole(RoleSlug::Parent, [
            'name' => 'Ada Obi',
            'email' => 'ada.obi@example.test',
        ]);
        $teacher = $this->userWithRole(RoleSlug::Teacher, [
            'name' => 'Mrs Eze',
            'email' => 'eze@example.test',
        ]);

        $this->actingAs($admin)->getJson('/api/v1/email-center/people')
            ->assertOk()
            ->assertJsonFragment(['email' => 'ada.obi@example.test'])
            ->assertJsonFragment(['email' => 'eze@example.test']);

        $this->actingAs($admin)->postJson('/api/v1/email-center/send', [
            'subject' => 'A word for Ada',
            'body' => 'Please call at the office.',
            'audience' => 'user',
            'user_ids' => [$parent->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.sent_count', 1)
            ->assertJsonPath('data.audience', 'user');

        Mail::assertSent(SchoolCircularMail::class, fn (SchoolCircularMail $mail) => $mail->hasTo($parent->email));
        Mail::assertNotSent(SchoolCircularMail::class, fn (SchoolCircularMail $mail) => $mail->hasTo($teacher->email));

        Mail::fake();

        $this->actingAs($admin)->postJson('/api/v1/email-center/send', [
            'subject' => 'A word for two houses',
            'body' => 'Kindly read this note today.',
            'audience' => 'users',
            'user_ids' => [$parent->id, $teacher->id],
        ])
            ->assertCreated()
            ->assertJsonPath('data.sent_count', 2)
            ->assertJsonPath('data.audience', 'users');

        Mail::assertSent(SchoolCircularMail::class, 2);
        Mail::assertSent(SchoolCircularMail::class, fn (SchoolCircularMail $mail) => $mail->hasTo($parent->email));
        Mail::assertSent(SchoolCircularMail::class, fn (SchoolCircularMail $mail) => $mail->hasTo($teacher->email));
    }

    public function test_one_person_requires_a_single_mailbox(): void
    {
        $admin = $this->admin();
        $parent = $this->userWithRole(RoleSlug::Parent);
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($admin)->postJson('/api/v1/email-center/send', [
            'subject' => 'Too many',
            'body' => 'This should not leave.',
            'audience' => 'user',
            'user_ids' => [$parent->id, $teacher->id],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['user_ids']);
    }

    public function test_empty_audience_is_rejected(): void
    {
        Mail::fake();

        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/email-center/send', [
            'subject' => 'Quiet term',
            'body' => 'No one to write to.',
            'audience' => 'parents',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }
}
