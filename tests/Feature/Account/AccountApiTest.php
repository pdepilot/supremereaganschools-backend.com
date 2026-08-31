<?php

namespace Tests\Feature\Account;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AccountApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_authenticated_admin_can_view_and_update_their_details(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $admin->id)
            ->assertJsonPath('data.email', $admin->email)
            ->assertJsonMissingPath('data.password');

        $this->actingAs($admin)
            ->putJson('/api/v1/me', [
                'name' => 'Mrs. Ezinne Ibeaja',
                'email' => 'ibeaja@supremereaganschools.test',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Account details updated.')
            ->assertJsonPath('data.name', 'Mrs. Ezinne Ibeaja')
            ->assertJsonPath('data.email', 'ibeaja@supremereaganschools.test')
            ->assertJsonMissingPath('data.password');

        $this->assertDatabaseHas('users', [
            'id' => $admin->id,
            'name' => 'Mrs. Ezinne Ibeaja',
            'email' => 'ibeaja@supremereaganschools.test',
        ]);
    }

    public function test_admin_can_reset_their_passphrase(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, [
            'email' => 'office@school.test',
            'password' => 'password',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'password',
                'password' => 'new-office-key',
                'password_confirmation' => 'new-office-key',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Passphrase reset.')
            ->assertJsonMissingPath('data.password');

        $this->assertTrue(Hash::check('new-office-key', $admin->fresh()->getAuthPassword()));
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_admin_can_sign_in_with_updated_email_and_passphrase(): void
    {
        $admin = $this->userWithRole(RoleSlug::SchoolAdmin, [
            'email' => 'office@school.test',
            'password' => 'password',
        ]);

        $this->actingAs($admin)
            ->putJson('/api/v1/me', [
                'name' => 'School Office',
                'email' => 'new-office@school.test',
            ])
            ->assertOk();

        $this->actingAs($admin)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'password',
                'password' => 'new-office-key',
                'password_confirmation' => 'new-office-key',
            ])
            ->assertOk();

        $this->post('/logout');

        $this->postJson('/login', [
            'email' => 'New-Office@school.test',
            'password' => 'new-office-key',
            'portal' => 'portal',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'new-office@school.test');
    }

    public function test_passphrase_reset_rejects_the_wrong_current_key(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/v1/me/password', [
                'current_password' => 'not-the-key',
                'password' => 'new-office-key',
                'password_confirmation' => 'new-office-key',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonStructure(['errors' => ['current_password']]);

        $this->assertTrue(Hash::check('password', $admin->fresh()->getAuthPassword()));
    }

    public function test_email_must_remain_unique(): void
    {
        $this->userWithRole(RoleSlug::Teacher, ['email' => 'taken@school.test']);
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/v1/me', [
                'name' => $admin->name,
                'email' => 'taken@school.test',
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_admin_can_list_desk_access(): void
    {
        $admin = $this->admin();
        $this->staff($this->userWithRole(RoleSlug::Teacher));

        $this->actingAs($admin)
            ->getJson('/api/v1/desk-access')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.admins.0.id', $admin->id)
            ->assertJsonPath('data.staff_count', 1);
    }

    public function test_unauthenticated_users_cannot_change_account_details(): void
    {
        $this->getJson('/api/v1/me')->assertUnauthorized();
        $this->putJson('/api/v1/me', [
            'name' => 'Hacked',
            'email' => 'hacked@school.test',
        ])->assertUnauthorized();
        $this->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'hacked-key',
            'password_confirmation' => 'hacked-key',
        ])->assertUnauthorized();
        $this->getJson('/api/v1/desk-access')->assertUnauthorized();
    }

    public function test_teachers_cannot_view_desk_access(): void
    {
        $this->actingAs($this->userWithRole(RoleSlug::Teacher))
            ->getJson('/api/v1/desk-access')
            ->assertForbidden()
            ->assertJsonPath('message', 'This action is unauthorized.');
    }
}
