<?php

namespace Tests\Feature;

use App\Enums\GuardianRelationship;
use App\Enums\RoleSlug;
use App\Enums\UserStatus;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_login_page_loads(): void
    {
        $this->get('/portal/login')
            ->assertOk()
            ->assertSee('Sovereign access', false)
            ->assertSee('Identify yourself.', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('/site/JS/portal-auth-scene.js', false)
            ->assertSee('data-auth-scene="panel"', false);
    }

    public function test_login_pages_rotate_aesthetic_scenes(): void
    {
        $this->get('/staff/login')
            ->assertOk()
            ->assertSee('/site/JS/portal-auth-scene.js', false)
            ->assertSee('data-auth-scene="panel"', false)
            ->assertSee('The staff desk.', false)
            ->assertSee('/site/CSS/staff-auth.css', false)
            ->assertSee('href="/staff/forgot-password"', false);

        $this->get('/parent/login')
            ->assertOk()
            ->assertSee('/site/JS/portal-auth-scene.js', false)
            ->assertSee('data-auth-scene="panel"', false)
            ->assertSee('The family desk.', false)
            ->assertSee('name="portal"', false)
            ->assertSee('value="parent"', false)
            ->assertSee('name="email"', false)
            ->assertSee('Phone number', false)
            ->assertSee('name="admission_number"', false);

        $this->get('/student/login')
            ->assertOk()
            ->assertSee('/site/JS/portal-auth-scene.js', false)
            ->assertSee('data-auth-scene="panel"', false)
            ->assertSee('The pupil desk.', false)
            ->assertSee('name="admission_number"', false)
            ->assertSee('Parent’s phone number', false)
            ->assertSee('value="student"', false);

        $script = (string) file_get_contents(public_path('site/JS/portal-auth-scene.js'));
        $this->assertStringContainsString('images.unsplash.com', $script);
        $this->assertStringContainsString('srs.auth-scene.index', $script);
    }

    public function test_legacy_login_paths_redirect_to_the_sovereign_login(): void
    {
        $this->get('/site/superAdminLogin.html')->assertRedirect(route('login'));
        $this->get('/site/adminLogin.html')->assertRedirect(route('login'));
        $this->get('/admin/login')->assertRedirect(route('login'));
        $this->get('/admin/home')->assertRedirect(route('portal.home'));
        $this->get('/admin/office-login')->assertRedirect(route('login'));
        $this->get('/site/admin/dashboard.html')->assertRedirect('/portal/dashboard');
    }

    public function test_valid_admin_login_succeeds_and_regenerates_the_session(): void
    {
        $user = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->get('/portal/login');
        $sessionId = session()->getId();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'portal',
        ])
            ->assertRedirect(route('portal.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($sessionId, session()->getId());
    }

    public function test_invalid_credentials_fail_without_revealing_the_account(): void
    {
        $user = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->from('/portal/login')
            ->post('/login', [
                'email' => $user->email,
                'password' => 'wrong-password',
                'portal' => 'portal',
            ])
            ->assertRedirect('/portal/login')
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_authenticated_user_can_open_a_portal_desk_page(): void
    {
        $user = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->actingAs($user)
            ->get('/portal/dashboard')
            ->assertOk()
            ->assertSee('Command Desk', false);

        $this->actingAs($user)
            ->get('/portal/home')
            ->assertOk()
            ->assertSee('Command Desk', false);
    }

    public function test_authenticated_user_can_access_the_protected_home_route(): void
    {
        $user = $this->userWithRole(RoleSlug::SuperAdmin);

        $this->actingAs($user)
            ->getJson('/portal/home')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_unauthenticated_user_cannot_access_the_protected_home_route(): void
    {
        $this->get('/portal/home')->assertRedirect(route('login'));

        $this->getJson('/portal/home')
            ->assertUnauthorized()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->actingAs($user);
        $this->assertAuthenticated();

        $this->post('/logout')->assertRedirect(route('login'));

        $this->assertGuest();
        $this->getJson('/portal/home')->assertUnauthorized();
    }

    public function test_login_validation_requires_email_and_password(): void
    {
        $this->from('/portal/login')
            ->post('/login', [])
            ->assertRedirect('/portal/login')
            ->assertSessionHasErrors(['email', 'password']);
    }

    public function test_passwords_are_hashed_and_not_returned(): void
    {
        $user = User::factory()->create([
            'password' => 'secret-pass',
        ]);

        $this->assertTrue(Hash::check('secret-pass', $user->getAuthPassword()));
        $this->assertNotSame('secret-pass', $user->getAuthPassword());

        $user->assignRole(RoleSlug::SchoolAdmin);

        $this->postJson('/login', [
            'email' => $user->email,
            'password' => 'secret-pass',
            'portal' => 'portal',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token');
    }

    public function test_role_assignment_and_checks_work(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->hasRole(RoleSlug::Teacher));

        $user->assignRole(RoleSlug::Teacher);

        $this->assertTrue($user->fresh()->hasRole(RoleSlug::Teacher));
        $this->assertTrue($user->fresh()->hasAnyRole(RoleSlug::Teacher, RoleSlug::Parent));
        $this->assertFalse($user->fresh()->hasRole(RoleSlug::SchoolAdmin));
    }

    public function test_authenticated_users_are_sent_to_their_desk_from_login(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $parent = $this->userWithRole(RoleSlug::Parent);
        $student = $this->userWithRole(RoleSlug::Student);

        $this->actingAs($admin)->get('/portal/login')->assertRedirect(route('portal.home'));
        $this->actingAs($teacher)->get('/staff/login')->assertRedirect(route('staff.home'));
        $this->actingAs($parent)->get('/parent/login')->assertRedirect(route('parent.home'));
        $this->actingAs($student)->get('/student/login')->assertRedirect(route('student.home'));
    }

    public function test_a_portal_session_still_opens_the_staff_login(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->actingAs($admin)
            ->get('/staff/login')
            ->assertOk()
            ->assertSee('The staff desk.', false)
            ->assertDontSee('Command Desk', false);

        $this->actingAs($admin)
            ->get('/parent/login')
            ->assertOk()
            ->assertSee('The family desk.', false);

        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->get('/portal/login')
            ->assertOk()
            ->assertSee('Identify yourself.', false);
    }

    public function test_json_login_returns_to_the_page_that_sent_the_guest_away(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->get('/staff/grades')->assertRedirect(route('staff.login'));

        $this->postJson('/login', [
            'email' => $teacher->email,
            'password' => 'password',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect', '/staff/grades');

        $this->assertAuthenticatedAs($teacher);
        $this->get('/staff/grades')->assertOk()->assertSee('data-page="grades"', false);
    }

    public function test_json_login_keeps_admin_on_the_command_desk(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);

        $this->get('/portal/home')->assertRedirect(route('login'));

        $this->postJson('/login', [
            'email' => $admin->email,
            'password' => 'password',
            'portal' => 'portal',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect', '/portal/home');

        $this->assertAuthenticatedAs($admin);
        $this->get('/portal/home')->assertOk()->assertSee('Command Desk', false);
    }

    public function test_json_login_ignores_an_intended_url_from_another_desk(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->get('/portal/home')->assertRedirect(route('login'));

        $this->postJson('/login', [
            'email' => $teacher->email,
            'password' => 'password',
            'portal' => 'staff',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect', route('staff.home', absolute: false));
    }

    public function test_an_admin_can_sign_into_the_staff_desk_from_a_portal_session(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($admin)
            ->post('/login', [
                'email' => $teacher->email,
                'password' => 'password',
                'portal' => 'staff',
            ])
            ->assertRedirect(route('staff.home'));

        $this->assertAuthenticatedAs($teacher);
        $this->get('/portal/home')->assertRedirect(route('staff.home'));
    }

    public function test_html_visits_to_the_wrong_desk_go_home(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin);
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($teacher)
            ->get('/portal/home')
            ->assertRedirect(route('staff.home'));

        $this->actingAs($admin)
            ->get('/staff/home')
            ->assertRedirect(route('portal.home'));

        $this->actingAs($parent)
            ->get('/portal/home')
            ->assertRedirect(route('parent.home'));
    }

    public function test_unauthorized_role_cannot_access_admin_home(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($teacher)
            ->getJson('/portal/home')
            ->assertForbidden();
    }

    public function test_teacher_cannot_sign_in_through_the_admin_portal(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->from('/portal/login')
            ->post('/login', [
                'email' => $teacher->email,
                'password' => 'password',
                'portal' => 'portal',
            ])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_users_cannot_sign_in(): void
    {
        $user = $this->userWithRole(RoleSlug::SchoolAdmin, [
            'status' => UserStatus::Inactive,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
            'portal' => 'portal',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_student_admission_login_is_not_available_without_student_profiles(): void
    {
        $student = $this->userWithRole(RoleSlug::Student);

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => 'password',
            'portal' => 'student',
        ])->assertSessionHasErrors('admission_number');

        $this->post('/login', [
            'email' => $student->email,
            'password' => 'password',
            'portal' => 'student',
        ])->assertSessionHasErrors('admission_number');

        $this->assertGuest();
    }

    public function test_parent_can_sign_in_with_email_and_access_parent_home(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->post('/login', [
            'email' => $parent->email,
            'password' => 'password',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));

        $this->getJson('/parent/home')
            ->assertOk()
            ->assertJsonPath('data.portal', 'parent');
    }

    public function test_parent_can_sign_in_with_email_and_registered_phone(): void
    {
        $child = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0888',
            'surname' => 'Okoro',
            'first_name' => 'Amaka',
        ]);
        $guardian = $this->guardian(null, [
            'full_name' => 'Mrs. Okoro',
            'phone' => '08039998877',
            'email' => 'okoro.parent@school.test',
        ]);
        $this->linkGuardian($guardian, $child, [
            'relationship' => GuardianRelationship::Mother,
            'is_primary' => true,
            'can_login' => true,
        ]);

        $this->post('/login', [
            'email' => 'okoro.parent@school.test',
            'password' => '08039998877',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));

        $this->assertAuthenticated();
        $this->assertTrue(auth()->user()->hasRole(RoleSlug::Parent));
    }

    public function test_student_can_sign_in_with_admission_number_and_parent_phone(): void
    {
        $child = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0999',
            'surname' => 'Ibe',
            'first_name' => 'Chinedu',
            'passphrase_set_at' => null,
        ]);
        $guardian = $this->guardian(null, [
            'full_name' => 'Mr. Ibe',
            'phone' => '08031234567',
            'email' => 'ibe.parent@school.test',
        ]);
        $this->linkGuardian($guardian, $child, [
            'relationship' => GuardianRelationship::Father,
            'is_primary' => true,
            'can_login' => true,
        ]);

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0999',
            'password' => '08031234567',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));

        $this->assertAuthenticatedAs($child->user);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userWithRole(RoleSlug $role, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
