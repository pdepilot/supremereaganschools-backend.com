<?php

namespace Tests\Feature;

use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Notifications\ResetDeskPassword;
use App\Services\PasswordResetService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_forgot_password_pages_load_for_office_desks(): void
    {
        $this->get('/portal/forgot-password')
            ->assertOk()
            ->assertSee('Request restoration.', false)
            ->assertSee('name="email"', false)
            ->assertSee('value="portal"', false)
            ->assertSee('portal-password.js', false);

        $this->get('/staff/forgot-password')
            ->assertOk()
            ->assertSee('Forgot the passphrase?', false)
            ->assertSee('value="staff"', false)
            ->assertSee('href="/staff/login"', false);

        $this->get('/parent/forgot-password')
            ->assertOk()
            ->assertSee('value="parent"', false)
            ->assertSee('the family desk', false);

        $this->get('/student/forgot-password')->assertRedirect(route('student.login'));
    }

    public function test_login_pages_link_to_restoration(): void
    {
        $this->get('/portal/login')->assertOk()->assertSee('href="/portal/forgot-password"', false);
        $this->get('/staff/login')->assertOk()->assertSee('href="/staff/forgot-password"', false);
        $this->get('/parent/login')->assertOk()->assertSee('href="/parent/forgot-password"', false);
        $this->get('/student/login')
            ->assertOk()
            ->assertDontSee('forgot-password', false)
            ->assertSee('Passphrase', false)
            ->assertDontSee('Parent’s phone number', false);
    }

    public function test_staff_can_reset_password_from_registered_email(): void
    {
        Notification::fake();

        $teacher = $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'eze@school.test',
            'password' => 'password',
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'eze@school.test',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        $token = null;
        Notification::assertSentTo($teacher, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return $notification->portal->value === 'staff';
        });

        $this->get('/staff/reset-password/'.$token.'?email=eze@school.test')
            ->assertOk()
            ->assertSee('name="token"', false)
            ->assertSee('value="'.$token.'"', false);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'eze@school.test',
            'password' => 'new-staff-key',
            'password_confirmation' => 'new-staff-key',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::RESET)
            ->assertJsonPath('data.redirect', '/staff/login');

        $this->assertTrue(Hash::check('new-staff-key', $teacher->fresh()->getAuthPassword()));
        $this->assertFalse(Hash::check('password', $teacher->fresh()->getAuthPassword()));

        $this->post('/login', [
            'email' => 'eze@school.test',
            'password' => 'new-staff-key',
            'portal' => 'staff',
        ])->assertRedirect(route('staff.home'));
    }

    public function test_admin_and_parent_receive_restoration_letters_on_their_desks(): void
    {
        Notification::fake();

        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, ['email' => 'office@school.test']);
        $parent = $this->userWithRole(RoleSlug::Parent, ['email' => 'okafor@family.test']);

        $this->postJson('/forgot-password', [
            'email' => 'office@school.test',
            'portal' => 'portal',
        ])->assertOk();

        Notification::assertSentTo($admin, ResetDeskPassword::class);

        $this->postJson('/forgot-password', [
            'email' => 'okafor@family.test',
            'portal' => 'parent',
        ])->assertOk();

        Notification::assertSentTo($parent, ResetDeskPassword::class);
    }

    public function test_unknown_or_wrong_desk_email_does_not_reveal_an_account(): void
    {
        Notification::fake();

        $teacher = $this->userWithRole(RoleSlug::Teacher, ['email' => 'eze@school.test']);

        $this->postJson('/forgot-password', [
            'email' => 'missing@school.test',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::SENT);

        $this->postJson('/forgot-password', [
            'email' => 'eze@school.test',
            'portal' => 'portal',
        ])->assertOk();

        Notification::assertNothingSent();
        $this->assertNotNull($teacher->fresh());
    }

    public function test_inactive_users_and_pupils_cannot_request_a_letter(): void
    {
        Notification::fake();

        $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'quiet@school.test',
            'status' => UserStatus::Inactive,
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'quiet@school.test',
            'portal' => 'staff',
        ])->assertOk();

        $this->postJson('/forgot-password', [
            'email' => 'quiet@school.test',
            'portal' => 'student',
        ])->assertUnprocessable();

        Notification::assertNothingSent();
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $this->userWithRole(RoleSlug::Teacher, ['email' => 'eze@school.test']);

        $this->postJson('/reset-password', [
            'token' => 'not-a-real-token',
            'email' => 'eze@school.test',
            'password' => 'new-staff-key',
            'password_confirmation' => 'new-staff-key',
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }
}
