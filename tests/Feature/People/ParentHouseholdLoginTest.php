<?php

namespace Tests\Feature\People;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class ParentHouseholdLoginTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_parent_login_offers_the_household_key(): void
    {
        $this->get('/parent/login')
            ->assertOk()
            ->assertSee('Child’s name or admission number', false)
            ->assertSee('Parent’s phone number', false)
            ->assertSee('name="admission_number"', false)
            ->assertSee('name="email"', false)
            ->assertSee('href="/parent/forgot-password"', false)
            ->assertSee('value="parent"', false);
    }

    public function test_parent_can_sign_in_with_admission_number_and_registered_phone(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student);
        $student = $this->student($pupil, ['admission_number' => 'SRS/2025/0142']);
        $guardian = $this->guardian(null, ['phone' => '08030000001', 'full_name' => 'Mrs. Okafor']);
        $this->linkGuardian($guardian, $student);

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));

        $user = $guardian->fresh()?->user;
        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole(RoleSlug::Parent));
        $this->assertFalse($user->hasRole(RoleSlug::Student));
    }

    public function test_parent_can_sign_in_with_child_name_and_formatted_phone(): void
    {
        $student = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
        ]);
        $this->linkGuardian($this->guardian(null, [
            'phone' => '08030000001',
            'alternate_phone' => '09030000002',
        ]), $student);

        $this->post('/login', [
            'admission_number' => 'Chiamaka Okafor',
            'password' => '+234 803 000 0001',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', [
            'admission_number' => 'Okafor Chiamaka',
            'password' => '09030000002',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));
    }

    public function test_one_parent_sees_every_linked_child_after_household_sign_in(): void
    {
        $parentUser = $this->userWithRole(RoleSlug::Parent, ['password' => 'secret-pass']);
        $guardian = $this->guardian($parentUser, ['phone' => '08030000001']);
        $first = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Okafor',
            'first_name' => 'Amara',
        ]);
        $second = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0198',
            'surname' => 'Okafor',
            'first_name' => 'Kamsi',
        ]);
        $this->linkGuardian($guardian, $first);
        $this->linkGuardian($guardian, $second, ['is_primary' => false]);

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0198',
            'password' => '08030000001',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));

        $this->assertAuthenticatedAs($parentUser);

        $desk = $this->getJson('/api/v1/parent-desk')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.metrics.children', 2)
            ->json('data.children');

        $this->assertEqualsCanonicalizing(
            ['Okafor Amara', 'Okafor Kamsi'],
            array_column($desk, 'full_name'),
        );
    }

    public function test_email_passphrase_door_still_opens_the_family_desk(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent, ['email' => 'okafor@family.test']);

        $this->post('/login', [
            'email' => 'okafor@family.test',
            'password' => 'password',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));

        $this->assertAuthenticatedAs($parent);
    }

    public function test_pupil_account_password_is_not_accepted_on_the_family_desk(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student, ['password' => 'secret-pass']);
        $student = $this->student($pupil, ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);

        $this->from('/parent/login')->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => 'secret-pass',
            'portal' => 'parent',
        ])
            ->assertRedirect('/parent/login')
            ->assertSessionHasErrors('admission_number');

        $this->assertGuest();
    }

    public function test_guardian_without_login_rights_cannot_open_the_family_desk(): void
    {
        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student, ['can_login' => false]);

        $this->from('/parent/login')->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'parent',
        ])
            ->assertRedirect('/parent/login')
            ->assertSessionHasErrors('admission_number');

        $this->assertGuest();
    }

    public function test_two_guardians_sharing_a_phone_on_one_child_are_rejected(): void
    {
        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001', 'full_name' => 'Mrs. Okafor']), $student);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001', 'full_name' => 'Mr. Okafor']), $student, [
            'is_primary' => false,
        ]);

        $this->from('/parent/login')->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'parent',
        ])
            ->assertRedirect('/parent/login')
            ->assertSessionHasErrors('admission_number');

        $this->assertGuest();
    }

    public function test_household_sign_in_does_not_consume_the_stored_passphrase(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent, ['password' => 'secret-pass']);
        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian($parent, ['phone' => '08030000001']), $student);

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'parent',
        ])->assertRedirect(route('parent.home'));

        $this->assertTrue(Hash::check('secret-pass', $parent->fresh()->getAuthPassword()));
    }
}
