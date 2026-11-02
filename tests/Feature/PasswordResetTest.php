<?php

namespace Tests\Feature;

use App\Enums\AuthPortal;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\GuardianProfile;
use App\Models\StudentProfile;
use App\Models\User;
use App\Notifications\ResetDeskPassword;
use App\Services\PasswordResetService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    public function test_forgot_password_pages_load_for_all_desks(): void
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
            ->assertSee('the family desk', false)
            ->assertSee('href="/parent/login"', false);

        $this->get('/student/forgot-password')
            ->assertOk()
            ->assertSee('value="student"', false)
            ->assertSee('the pupil desk', false)
            ->assertSee('href="/student/login"', false);
    }

    public function test_login_pages_link_to_restoration(): void
    {
        $this->get('/portal/login')->assertOk()->assertSee('href="/portal/forgot-password"', false);
        $this->get('/staff/login')->assertOk()->assertSee('href="/staff/forgot-password"', false);
        $this->get('/parent/login')->assertOk()->assertSee('href="/parent/forgot-password"', false);
        $this->get('/student/login')->assertOk()->assertSee('href="/student/forgot-password"', false);
    }

    public function test_admin_can_reset_password_from_registered_email(): void
    {
        Notification::fake();

        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, [
            'email' => 'admin@school.test',
            'password' => 'old-admin-key',
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'admin@school.test',
            'portal' => 'portal',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        $token = null;
        Notification::assertSentTo($admin, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return $notification->portal === AuthPortal::Portal;
        });

        $this->get('/portal/reset-password/'.$token.'?email=admin@school.test')
            ->assertOk()
            ->assertSee('name="token"', false)
            ->assertSee('value="'.$token.'"', false);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'admin@school.test',
            'password' => 'new-admin-key',
            'password_confirmation' => 'new-admin-key',
            'portal' => 'portal',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::RESET)
            ->assertJsonPath('data.redirect', '/portal/login');

        $this->assertTrue(Hash::check('new-admin-key', $admin->fresh()->getAuthPassword()));
        $this->assertFalse(Hash::check('old-admin-key', $admin->fresh()->getAuthPassword()));

        $this->post('/login', [
            'email' => 'admin@school.test',
            'password' => 'new-admin-key',
            'portal' => 'portal',
        ])->assertRedirect(route('portal.home'));
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

            return $notification->portal === AuthPortal::Staff;
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

    public function test_parent_can_reset_password_from_registered_email(): void
    {
        Notification::fake();

        $parent = $this->userWithRole(RoleSlug::Parent, [
            'email' => 'okafor@family.test',
            'password' => 'old-parent-pass',
        ]);
        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian($parent, ['phone' => '08030000001', 'email' => 'okafor@family.test']), $student);

        $this->postJson('/forgot-password', [
            'email' => 'okafor@family.test',
            'portal' => 'parent',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::SENT);

        $token = null;
        Notification::assertSentTo($parent, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return $notification->portal === AuthPortal::Parent;
        });

        $this->get('/parent/reset-password/'.$token.'?email=okafor@family.test')
            ->assertOk()
            ->assertSee('name="token"', false)
            ->assertSee('value="'.$token.'"', false);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'okafor@family.test',
            'password' => 'new-parent-pass',
            'password_confirmation' => 'new-parent-pass',
            'portal' => 'parent',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::RESET)
            ->assertJsonPath('data.redirect', '/parent/login');

        $this->assertTrue(Hash::check('new-parent-pass', $parent->fresh()->getAuthPassword()));

        $this->post('/login', [
            'email' => 'okafor@family.test',
            'password' => 'new-parent-pass',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));
    }

    public function test_parent_password_recovery_does_not_collide_with_existing_non_parent_user(): void
    {
        Notification::fake();

        $teacher = $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'staff.parent@school.test',
            'password' => 'teacher-password',
        ]);

        $student = $this->student($this->userWithRole(RoleSlug::Student));
        $guardian = $this->guardian(null, [
            'full_name' => 'Staff Guardian',
            'email' => 'staff.parent@school.test',
            'phone' => '08039999999',
        ]);
        $this->linkGuardian($guardian, $student);

        $this->postJson('/forgot-password', [
            'email' => 'staff.parent@school.test',
            'portal' => 'parent',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        Notification::assertNothingSent();

        $this->assertFalse($teacher->fresh()->hasRole(RoleSlug::Parent));
        $this->assertNull($guardian->fresh()->user_id);
        $this->assertSame(2, User::query()->count());
    }

    public function test_unlinked_guardian_profile_creates_parent_user_only_after_token_verification(): void
    {
        Notification::fake();

        $student = $this->student($this->userWithRole(RoleSlug::Student));
        $guardian = $this->guardian(null, [
            'full_name' => 'Mrs. Amaka Okoye',
            'email' => 'amaka.okoye@family.test',
            'phone' => '08031112233',
        ]);
        $this->linkGuardian($guardian, $student);

        $initialUserCount = User::query()->count();

        // 1. Unauthenticated request to /forgot-password
        $this->postJson('/forgot-password', [
            'email' => 'amaka.okoye@family.test',
            'portal' => 'parent',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::SENT);

        // Pre-verification database immutability: zero user creation or linking before verification
        $this->assertSame($initialUserCount, User::query()->count());
        $this->assertNull($guardian->fresh()->user_id);
        $this->assertFalse(User::query()->where('email', 'amaka.okoye@family.test')->exists());

        $token = null;
        Notification::assertSentTo($guardian, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return $notification->portal === AuthPortal::Parent;
        });

        // 2. Submit token verification to /reset-password
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'amaka.okoye@family.test',
            'password' => 'new-verified-parent-pass',
            'password_confirmation' => 'new-verified-parent-pass',
            'portal' => 'parent',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::RESET)
            ->assertJsonPath('data.redirect', '/parent/login');

        // Now that email ownership is verified, User is created and linked
        $this->assertSame($initialUserCount + 1, User::query()->count());
        $newUser = User::query()->where('email', 'amaka.okoye@family.test')->first();
        $this->assertNotNull($newUser);
        $this->assertTrue($newUser->hasRole(RoleSlug::Parent));
        $this->assertSame($newUser->id, $guardian->fresh()->user_id);
        $this->assertTrue(Hash::check('new-verified-parent-pass', $newUser->getAuthPassword()));

        // Parent can log in with new credentials
        $this->post('/login', [
            'email' => 'amaka.okoye@family.test',
            'password' => 'new-verified-parent-pass',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));
    }

    public function test_student_can_reset_password_and_login_with_admission_number(): void
    {
        Notification::fake();

        $pupil = $this->userWithRole(RoleSlug::Student, [
            'email' => 'chiamaka@student.test',
            'password' => 'old-passphrase',
        ]);
        $student = $this->student($pupil, [
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
        ]);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);

        $this->postJson('/forgot-password', [
            'email' => 'chiamaka@student.test',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        $token = null;
        Notification::assertSentTo($pupil, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return $notification->portal === AuthPortal::Student;
        });

        $this->get('/student/reset-password/'.$token.'?email=chiamaka@student.test')
            ->assertOk()
            ->assertSee('name="token"', false)
            ->assertSee('value="'.$token.'"', false);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'chiamaka@student.test',
            'password' => 'new-pupil-passphrase',
            'password_confirmation' => 'new-pupil-passphrase',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::RESET)
            ->assertJsonPath('data.redirect', '/student/login');

        $this->assertTrue(Hash::check('new-pupil-passphrase', $pupil->fresh()->getAuthPassword()));
        $this->assertFalse(Hash::check('old-passphrase', $pupil->fresh()->getAuthPassword()));
        $this->assertTrue($student->fresh()->hasPassphrase());

        // Admission number login with new passphrase works
        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => 'new-pupil-passphrase',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));

        $this->assertAuthenticatedAs($pupil);

        // Old passphrase is no longer accepted
        $this->post('/logout');
        $this->assertGuest();

        $this->from('/student/login')->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => 'old-passphrase',
            'portal' => 'student',
        ])
            ->assertRedirect('/student/login')
            ->assertSessionHasErrors('admission_number');

        $this->assertGuest();

        // Guardian phone fallback is no longer accepted now that a passphrase has been set
        $this->from('/student/login')->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'student',
        ])
            ->assertRedirect('/student/login')
            ->assertSessionHasErrors('admission_number');

        $this->assertGuest();
    }

    public function test_student_with_email_on_profile_receives_reset_notification(): void
    {
        Notification::fake();

        $pupil = $this->userWithRole(RoleSlug::Student, [
            'email' => 'srs20250199@students.supremereaganschools.invalid',
        ]);
        $student = $this->student($pupil, [
            'admission_number' => 'SRS/2025/0199',
            'email' => 'somto.pupil@gmail.com',
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'somto.pupil@gmail.com',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        $token = null;
        Notification::assertSentTo($pupil, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // Pre-verification immutability: pupil email must not change on unauthenticated forgot-password
        $this->assertSame('srs20250199@students.supremereaganschools.invalid', $pupil->fresh()->email);

        // Reset password verifies token and synchronizes email
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'somto.pupil@gmail.com',
            'password' => 'brand-new-somto-key',
            'password_confirmation' => 'brand-new-somto-key',
            'portal' => 'student',
        ])->assertOk();

        $this->assertSame('somto.pupil@gmail.com', $pupil->fresh()->email);
        $this->assertTrue(Hash::check('brand-new-somto-key', $pupil->fresh()->getAuthPassword()));
    }

    public function test_duplicate_student_emails_are_safely_rejected_without_mutation(): void
    {
        Notification::fake();

        $pupil1 = $this->userWithRole(RoleSlug::Student, ['email' => 'srs20250001@students.supremereaganschools.invalid']);
        $this->student($pupil1, [
            'admission_number' => 'SRS/2025/0001',
            'email' => 'siblings.shared@family.test',
        ]);

        $pupil2 = $this->userWithRole(RoleSlug::Student, ['email' => 'srs20250002@students.supremereaganschools.invalid']);
        $this->student($pupil2, [
            'admission_number' => 'SRS/2025/0002',
            'email' => 'siblings.shared@family.test',
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'siblings.shared@family.test',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::SENT);

        Notification::assertNothingSent();

        $this->assertSame('srs20250001@students.supremereaganschools.invalid', $pupil1->fresh()->email);
        $this->assertSame('srs20250002@students.supremereaganschools.invalid', $pupil2->fresh()->email);
    }

    public function test_student_without_eligible_email_receives_generic_response_without_notification(): void
    {
        Notification::fake();

        $pupil = $this->userWithRole(RoleSlug::Student, [
            'email' => 'srs20250199@students.supremereaganschools.invalid',
        ]);
        $this->student($pupil, [
            'admission_number' => 'SRS/2025/0199',
            'email' => null,
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'srs20250199@students.supremereaganschools.invalid',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::SENT);

        Notification::assertNothingSent();
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

        // Teacher email submitted to portal/admin desk does not send letter
        $this->postJson('/forgot-password', [
            'email' => 'eze@school.test',
            'portal' => 'portal',
        ])->assertOk();

        Notification::assertNothingSent();
        $this->assertNotNull($teacher->fresh());
    }

    public function test_inactive_users_cannot_request_a_letter(): void
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

        Notification::assertNothingSent();
    }

    public function test_token_cannot_be_reused_after_successful_reset(): void
    {
        Notification::fake();

        $teacher = $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'teacher@school.test',
            'password' => 'first-password',
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'teacher@school.test',
            'portal' => 'staff',
        ])->assertOk();

        $token = null;
        Notification::assertSentTo($teacher, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // First reset succeeds
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'teacher@school.test',
            'password' => 'brand-new-pass-1',
            'password_confirmation' => 'brand-new-pass-1',
            'portal' => 'staff',
        ])->assertOk();

        // Second reset with the same token is rejected
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'teacher@school.test',
            'password' => 'brand-new-pass-2',
            'password_confirmation' => 'brand-new-pass-2',
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_invalid_and_expired_tokens_are_rejected(): void
    {
        Notification::fake();

        $teacher = $this->userWithRole(RoleSlug::Teacher, ['email' => 'teacher@school.test']);

        // Invalid token
        $this->postJson('/reset-password', [
            'token' => 'invalid-token-string',
            'email' => 'teacher@school.test',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        // Expired token (> 60 minutes)
        $this->postJson('/forgot-password', [
            'email' => 'teacher@school.test',
            'portal' => 'staff',
        ])->assertOk();

        $token = null;
        Notification::assertSentTo($teacher, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        Carbon::setTestNow(now()->addMinutes(65));

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'teacher@school.test',
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        Carbon::setTestNow();
    }

    public function test_password_confirmation_must_match(): void
    {
        $this->userWithRole(RoleSlug::Teacher, ['email' => 'teacher@school.test']);

        $this->postJson('/reset-password', [
            'token' => 'some-token',
            'email' => 'teacher@school.test',
            'password' => 'valid-pass-123',
            'password_confirmation' => 'different-pass-456',
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_cross_portal_token_abuse_is_prevented(): void
    {
        Notification::fake();

        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, ['email' => 'admin@school.test']);

        $this->postJson('/forgot-password', [
            'email' => 'admin@school.test',
            'portal' => 'portal',
        ])->assertOk();

        $token = null;
        Notification::assertSentTo($admin, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // Admin token cannot be used to reset password via student or staff portal
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'admin@school.test',
            'password' => 'attempted-bypass',
            'password_confirmation' => 'attempted-bypass',
            'portal' => 'student',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'admin@school.test',
            'password' => 'attempted-bypass',
            'password_confirmation' => 'attempted-bypass',
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_successful_password_reset_rotates_remember_token(): void
    {
        Notification::fake();

        $teacher = $this->userWithRole(RoleSlug::Teacher, [
            'email' => 'teacher.remember@school.test',
            'remember_token' => 'original-fixed-remember-token-value',
        ]);

        $this->postJson('/forgot-password', [
            'email' => 'teacher.remember@school.test',
            'portal' => 'staff',
        ])->assertOk();

        $token = null;
        Notification::assertSentTo($teacher, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'teacher.remember@school.test',
            'password' => 'new-teacher-password',
            'password_confirmation' => 'new-teacher-password',
            'portal' => 'staff',
        ])->assertOk();

        $this->assertNotEmpty($teacher->fresh()->remember_token);
        $this->assertNotSame('original-fixed-remember-token-value', $teacher->fresh()->remember_token);
    }

    public function test_parent_and_student_credentials_are_completely_independent(): void
    {
        Notification::fake();

        $parent = $this->userWithRole(RoleSlug::Parent, [
            'email' => 'parent.indy@family.test',
            'password' => 'initial-parent-pass',
        ]);
        $guardian = $this->guardian($parent, [
            'phone' => '08035555555',
            'email' => 'parent.indy@family.test',
        ]);

        $pupil = $this->userWithRole(RoleSlug::Student, [
            'email' => 'pupil.indy@student.test',
            'password' => 'initial-pupil-pass',
        ]);
        $student = $this->student($pupil, [
            'admission_number' => 'SRS/2025/9999',
            'email' => 'pupil.indy@student.test',
            'passphrase_set_at' => now()->subDays(5),
        ]);
        $this->linkGuardian($guardian, $student);

        $initialStudentPassword = $pupil->fresh()->password;
        $initialStudentPassphraseSetAt = $student->fresh()->passphrase_set_at->toIso8601String();

        // A. Reset parent password
        $this->postJson('/forgot-password', [
            'email' => 'parent.indy@family.test',
            'portal' => 'parent',
        ])->assertOk();

        $parentToken = null;
        Notification::assertSentTo($parent, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$parentToken) {
            $parentToken = $notification->token;

            return true;
        });

        $this->postJson('/reset-password', [
            'token' => $parentToken,
            'email' => 'parent.indy@family.test',
            'password' => 'updated-parent-pass',
            'password_confirmation' => 'updated-parent-pass',
            'portal' => 'parent',
        ])->assertOk();

        // Verify parent password changed
        $this->assertTrue(Hash::check('updated-parent-pass', $parent->fresh()->getAuthPassword()));

        // Verify student credentials and guardian phone are UNTOUCHED
        $this->assertSame($initialStudentPassword, $pupil->fresh()->password);
        $this->assertSame($initialStudentPassphraseSetAt, $student->fresh()->passphrase_set_at->toIso8601String());
        $this->assertSame('08035555555', $guardian->fresh()->phone);

        // B. Reset student passphrase
        $this->postJson('/forgot-password', [
            'email' => 'pupil.indy@student.test',
            'portal' => 'student',
        ])->assertOk();

        $pupilToken = null;
        Notification::assertSentTo($pupil, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$pupilToken) {
            $pupilToken = $notification->token;

            return true;
        });

        $this->postJson('/reset-password', [
            'token' => $pupilToken,
            'email' => 'pupil.indy@student.test',
            'password' => 'updated-pupil-pass',
            'password_confirmation' => 'updated-pupil-pass',
            'portal' => 'student',
        ])->assertOk();

        // Verify student password changed
        $this->assertTrue(Hash::check('updated-pupil-pass', $pupil->fresh()->getAuthPassword()));

        // Verify parent credentials and guardian phone remain UNTOUCHED
        $this->assertTrue(Hash::check('updated-parent-pass', $parent->fresh()->getAuthPassword()));
        $this->assertSame('08035555555', $guardian->fresh()->phone);

        // Student can sign in with admission number and updated passphrase
        $this->post('/login', [
            'admission_number' => 'SRS/2025/9999',
            'password' => 'updated-pupil-pass',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));
    }

    public function test_cross_portal_token_isolation_with_identical_email_addresses(): void
    {
        Notification::fake();

        $staff = $this->userWithRole(RoleSlug::Teacher, ['email' => 'shared.address@domain.test']);

        // Request token on staff desk
        $this->postJson('/forgot-password', [
            'email' => 'shared.address@domain.test',
            'portal' => 'staff',
        ])->assertOk();

        $token = null;
        Notification::assertSentTo($staff, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // Attempting to reset using that token against the parent portal fails at token verification
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'shared.address@domain.test',
            'password' => 'hacked-portal-pass',
            'password_confirmation' => 'hacked-portal-pass',
            'portal' => 'parent',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);

        // Attempting against student portal fails
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'shared.address@domain.test',
            'password' => 'hacked-portal-pass',
            'password_confirmation' => 'hacked-portal-pass',
            'portal' => 'student',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false);
    }

    public function test_rate_limiting_protects_forgot_password_endpoint(): void
    {
        $email = 'spam-test@school.test';

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/forgot-password', [
                'email' => $email,
                'portal' => 'staff',
            ])->assertOk();
        }

        // 6th attempt is throttled
        $this->postJson('/forgot-password', [
            'email' => $email,
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_rate_limiting_protects_reset_password_endpoint(): void
    {
        $email = 'spam-reset@school.test';

        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/reset-password', [
                'token' => 'invalid-token-'.$i,
                'email' => $email,
                'password' => 'some-password-123',
                'password_confirmation' => 'some-password-123',
                'portal' => 'staff',
            ])->assertUnprocessable();
        }

        // 11th attempt is throttled
        $this->postJson('/reset-password', [
            'token' => 'invalid-token-11',
            'email' => $email,
            'password' => 'some-password-123',
            'password_confirmation' => 'some-password-123',
            'portal' => 'staff',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_duplicate_guardian_emails_are_safely_resolved_or_rejected(): void
    {
        Notification::fake();

        // Part A: Two GuardianProfile records share an email and link to the SAME parent User -> Resolved!
        $parent = $this->userWithRole(RoleSlug::Parent, [
            'email' => 'duplicate.guardian@family.test',
            'password' => 'parent-password-1',
        ]);
        $student1 = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/1101']);
        $student2 = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/1102']);

        $guardianA = $this->guardian($parent, ['email' => 'duplicate.guardian@family.test', 'phone' => '08011110001']);
        $guardianB = $this->guardian(null, ['email' => 'duplicate.guardian@family.test', 'phone' => '08011110002']);
        $this->linkGuardian($guardianA, $student1);
        $this->linkGuardian($guardianB, $student2);

        $this->postJson('/forgot-password', [
            'email' => 'duplicate.guardian@family.test',
            'portal' => 'parent',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        Notification::assertSentTo($parent, ResetDeskPassword::class);

        // Part B: Two GuardianProfile records share an email but link to DIFFERENT parent Users -> Rejected safely!
        Notification::fake();

        $parentA = $this->userWithRole(RoleSlug::Parent, ['email' => 'parent.a@family.test']);
        $parentB = $this->userWithRole(RoleSlug::Parent, ['email' => 'parent.b@family.test']);

        $guardianConfA = $this->guardian($parentA, ['email' => 'conflicted.guardian@family.test', 'phone' => '08022220001']);
        $guardianConfB = $this->guardian($parentB, ['email' => 'conflicted.guardian@family.test', 'phone' => '08022220002']);

        $this->postJson('/forgot-password', [
            'email' => 'conflicted.guardian@family.test',
            'portal' => 'parent',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        Notification::assertNothingSent();
    }

    public function test_unlinked_student_profile_creates_user_only_after_token_verification(): void
    {
        Notification::fake();

        $student = $this->student(null, [
            'user_id' => null,
            'admission_number' => 'SRS/2025/7788',
            'surname' => 'Eze',
            'first_name' => 'Kamsi',
            'status' => \App\Enums\StudentStatus::Active,
            'email' => null,
        ]);

        $guardian = $this->guardian(null, [
            'full_name' => 'Chief Eze',
            'email' => 'chief.eze@family.test',
            'phone' => '08037788990',
        ]);
        $this->linkGuardian($guardian, $student);

        $initialUserCount = User::query()->count();

        // 1. Unauthenticated forgot-password request
        $this->postJson('/forgot-password', [
            'email' => 'chief.eze@family.test',
            'admission_number' => 'SRS/2025/7788',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        // Pre-verification immutability: zero user creation before verification
        $this->assertSame($initialUserCount, User::query()->count());
        $this->assertNull($student->fresh()->user_id);
        $this->assertNull($student->fresh()->passphrase_set_at);

        $token = null;
        Notification::assertSentTo($student, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return $notification->portal === AuthPortal::Student;
        });

        // 2. Submit token verification to /reset-password
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'chief.eze@family.test',
            'admission_number' => 'SRS/2025/7788',
            'password' => 'first-student-pass-123',
            'password_confirmation' => 'first-student-pass-123',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('message', PasswordResetService::RESET)
            ->assertJsonPath('data.redirect', '/student/login');

        // Verified: User created with placeholder email (NOT guardian email)
        $this->assertSame($initialUserCount + 1, User::query()->count());
        $studentUser = $student->fresh()->user;
        $this->assertNotNull($studentUser);
        $this->assertTrue($studentUser->hasRole(RoleSlug::Student));
        $this->assertNotSame('chief.eze@family.test', $studentUser->email);
        $this->assertStringContainsString('students.supremereaganschools.invalid', $studentUser->email);
        $this->assertTrue(Hash::check('first-student-pass-123', $studentUser->getAuthPassword()));
        $this->assertTrue($student->fresh()->hasPassphrase());

        // Can sign in using admission number and new passphrase
        $this->post('/login', [
            'admission_number' => 'SRS/2025/7788',
            'password' => 'first-student-pass-123',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));
    }

    public function test_sibling_with_shared_guardian_email_resets_only_their_own_passphrase(): void
    {
        Notification::fake();

        $guardianUser = $this->userWithRole(RoleSlug::Parent, [
            'email' => 'parent.twochildren@family.test',
            'password' => 'parent-master-pass',
        ]);
        $guardian = $this->guardian($guardianUser, [
            'email' => 'parent.twochildren@family.test',
            'phone' => '08099887766',
        ]);

        $child1User = $this->userWithRole(RoleSlug::Student, [
            'email' => 'srs20250001@students.supremereaganschools.invalid',
            'password' => 'child-one-old-pass',
        ]);
        $child1 = $this->student($child1User, [
            'admission_number' => 'SRS/2025/0001',
            'surname' => 'Okoro',
            'first_name' => 'Emeka',
            'passphrase_set_at' => now()->subMonth(),
        ]);
        $this->linkGuardian($guardian, $child1);

        $child2User = $this->userWithRole(RoleSlug::Student, [
            'email' => 'srs20250002@students.supremereaganschools.invalid',
            'password' => 'child-two-old-pass',
        ]);
        $child2 = $this->student($child2User, [
            'admission_number' => 'SRS/2025/0002',
            'surname' => 'Okoro',
            'first_name' => 'Ada',
            'passphrase_set_at' => now()->subMonth(),
        ]);
        $this->linkGuardian($guardian, $child2);

        // Child 1 requests reset using parent email + admission number
        $this->postJson('/forgot-password', [
            'email' => 'parent.twochildren@family.test',
            'admission_number' => 'SRS/2025/0001',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        $token = null;
        Notification::assertSentTo($child1User, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return $notification->admissionNumber === 'SRS/2025/0001';
        });

        // Child 1 completes password reset
        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'parent.twochildren@family.test',
            'admission_number' => 'SRS/2025/0001',
            'password' => 'child-one-NEW-pass',
            'password_confirmation' => 'child-one-NEW-pass',
            'portal' => 'student',
        ])->assertOk();

        // Verify Child 1 password changed
        $this->assertTrue(Hash::check('child-one-NEW-pass', $child1User->fresh()->getAuthPassword()));

        // Verify Child 2 password and credentials are UNTOUCHED
        $this->assertTrue(Hash::check('child-two-old-pass', $child2User->fresh()->getAuthPassword()));
        $this->assertFalse(Hash::check('child-one-NEW-pass', $child2User->fresh()->getAuthPassword()));

        // Verify Parent credentials are UNTOUCHED
        $this->assertTrue(Hash::check('parent-master-pass', $guardianUser->fresh()->getAuthPassword()));

        // Child 1 signs in with new passphrase
        $this->post('/login', [
            'admission_number' => 'SRS/2025/0001',
            'password' => 'child-one-NEW-pass',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));
        $this->post('/logout');

        // Child 2 signs in with old passphrase
        $this->post('/login', [
            'admission_number' => 'SRS/2025/0002',
            'password' => 'child-two-old-pass',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));
    }

    public function test_parent_with_multiple_children_recovers_single_family_account(): void
    {
        Notification::fake();

        $parent = $this->userWithRole(RoleSlug::Parent, [
            'email' => 'family.multi@family.test',
            'password' => 'old-family-account-pass',
        ]);
        $guardian = $this->guardian($parent, [
            'email' => 'family.multi@family.test',
            'phone' => '08088776655',
        ]);

        $child1 = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0501']);
        $child2 = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0502']);
        $this->linkGuardian($guardian, $child1);
        $this->linkGuardian($guardian, $child2);

        $this->postJson('/forgot-password', [
            'email' => 'family.multi@family.test',
            'portal' => 'parent',
        ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $token = null;
        Notification::assertSentTo($parent, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        $this->postJson('/reset-password', [
            'token' => $token,
            'email' => 'family.multi@family.test',
            'password' => 'new-family-account-pass',
            'password_confirmation' => 'new-family-account-pass',
            'portal' => 'parent',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-family-account-pass', $parent->fresh()->getAuthPassword()));

        // Parent signs in and retains access to both children
        $this->post('/login', [
            'email' => 'family.multi@family.test',
            'password' => 'new-family-account-pass',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));

        $this->assertSame(2, $guardian->fresh()->students()->count());
    }

    public function test_reset_transaction_rolls_back_completely_on_failure(): void
    {
        Notification::fake();

        $student = $this->student(null, [
            'user_id' => null,
            'admission_number' => 'SRS/2025/9991',
            'surname' => 'Rollback',
            'first_name' => 'Test',
            'status' => \App\Enums\StudentStatus::Active,
        ]);

        $guardian = $this->guardian(null, [
            'email' => 'rollback@family.test',
            'phone' => '08077665544',
        ]);
        $this->linkGuardian($guardian, $student);

        $initialUserCount = User::query()->count();

        $this->postJson('/forgot-password', [
            'email' => 'rollback@family.test',
            'admission_number' => 'SRS/2025/9991',
            'portal' => 'student',
        ])->assertOk();

        $token = null;
        Notification::assertSentTo($student, ResetDeskPassword::class, function (ResetDeskPassword $notification) use (&$token) {
            $token = $notification->token;

            return true;
        });

        // Intercept User creation inside the reset transaction to throw an exception
        \App\Models\User::saving(function ($user) {
            if ($user->email && str_contains($user->email, '9991')) {
                throw new \RuntimeException('Simulated database write failure during reset');
            }
        });

        try {
            $this->postJson('/reset-password', [
                'token' => $token,
                'email' => 'rollback@family.test',
                'admission_number' => 'SRS/2025/9991',
                'password' => 'aborted-password',
                'password_confirmation' => 'aborted-password',
                'portal' => 'student',
            ]);
        } catch (\RuntimeException $e) {
            // Expected exception
        }

        // Transaction rollback verified: No user created, student profile remains unlinked
        $this->assertSame($initialUserCount, User::query()->count());
        $this->assertNull($student->fresh()->user_id);
    }

    public function test_smtp_transport_failure_returns_generic_response_without_http_500(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher, ['email' => 'smtp.fail@school.test']);

        Notification::shouldReceive('send')
            ->andThrow(new \Symfony\Component\Mailer\Exception\TransportException('SMTP Connection timed out.'));

        $this->postJson('/forgot-password', [
            'email' => 'smtp.fail@school.test',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        // Failed attempt must clean up orphan token
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'staff|smtp.fail@school.test',
        ]);
    }

    public function test_admission_number_recovery_rejects_unauthorized_email(): void
    {
        Notification::fake();

        $pupil = $this->userWithRole(RoleSlug::Student, ['email' => 'legit.pupil@students.supremereaganschools.invalid']);
        $student = $this->student($pupil, [
            'admission_number' => 'SRS/2025/5544',
            'email' => 'legit.pupil@gmail.com',
        ]);
        $guardian = $this->guardian(null, [
            'email' => 'legit.parent@family.test',
            'phone' => '08033334444',
        ]);
        $this->linkGuardian($guardian, $student);

        // Attacker attempts recovery for SRS/2025/5544 with an unauthorized email
        $this->postJson('/forgot-password', [
            'email' => 'attacker@unauthorized.test',
            'admission_number' => 'SRS/2025/5544',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        Notification::assertNothingSent();

        // Token was never created for the attacker
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'student|srs/2025/5544',
        ]);
    }

    public function test_shared_guardian_email_cannot_reset_sibling_without_admission_number(): void
    {
        Notification::fake();

        $guardian = $this->guardian(null, [
            'email' => 'shared.guardian.two@family.test',
            'phone' => '08055556666',
        ]);

        $pupil1 = $this->userWithRole(RoleSlug::Student, ['email' => 'srs20250701@students.supremereaganschools.invalid']);
        $student1 = $this->student($pupil1, ['admission_number' => 'SRS/2025/0701']);
        $this->linkGuardian($guardian, $student1);

        $pupil2 = $this->userWithRole(RoleSlug::Student, ['email' => 'srs20250702@students.supremereaganschools.invalid']);
        $student2 = $this->student($pupil2, ['admission_number' => 'SRS/2025/0702']);
        $this->linkGuardian($guardian, $student2);

        // Attempt student reset with shared guardian email WITHOUT providing admission_number
        $this->postJson('/forgot-password', [
            'email' => 'shared.guardian.two@family.test',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', PasswordResetService::SENT);

        Notification::assertNothingSent();
    }
}
