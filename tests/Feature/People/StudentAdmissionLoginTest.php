<?php

namespace Tests\Feature\People;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StudentAdmissionLoginTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_student_can_sign_in_with_admission_number_and_parent_phone_until_a_passphrase_is_set(): void
    {
        $user = $this->userWithRole(RoleSlug::Student, ['password' => 'secret-pass']);
        $student = $this->student($user, ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('secret-pass', $student->fresh()->user->getAuthPassword()));
    }

    public function test_student_can_sign_in_with_name_and_formatted_parent_phone(): void
    {
        $user = $this->userWithRole(RoleSlug::Student);
        $student = $this->student($user, [
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
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));

        $this->post('/logout');
        $this->assertGuest();

        $this->post('/login', [
            'admission_number' => 'Okafor Chiamaka',
            'password' => '09030000002',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));
    }

    public function test_student_passphrase_opens_the_desk_and_retires_the_parent_phone(): void
    {
        $user = $this->userWithRole(RoleSlug::Student, ['password' => 'secret-pass']);
        $student = $this->student($user, ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);

        $this->actingAs($this->admin())->putJson('/api/v1/students/'.$student->id, [
            'password' => 'pupil-desk-key',
        ])->assertOk();

        $this->post('/logout');

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => 'pupil-desk-key',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($student->fresh()->passphrase_set_at);

        $this->post('/logout');

        $this->from('/student/login')->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'student',
        ])
            ->assertRedirect('/student/login')
            ->assertSessionHasErrors('admission_number');

        $this->assertGuest();
    }

    public function test_stored_pupil_passphrase_is_accepted_before_the_office_marks_it_issued(): void
    {
        $user = $this->userWithRole(RoleSlug::Student, ['password' => 'secret-pass']);
        $student = $this->student($user, ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => 'secret-pass',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_incorrect_parent_phone_fails(): void
    {
        $user = $this->userWithRole(RoleSlug::Student);
        $student = $this->student($user, ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);

        $this->from('/student/login')->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08039999999',
            'portal' => 'student',
        ])
            ->assertRedirect('/student/login')
            ->assertSessionHasErrors('admission_number');

        $this->assertGuest();
    }

    public function test_student_login_resolves_the_correct_user_and_logout_works(): void
    {
        $user = $this->userWithRole(RoleSlug::Student);
        $student = $this->student($user, ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);
        $other = $this->student($this->userWithRole(RoleSlug::Student, ['email' => 'other@school.test']), [
            'admission_number' => 'SRS/2025/0198',
        ]);
        $this->linkGuardian($this->guardian(null, ['phone' => '08031110011']), $other);

        $this->postJson('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonMissingPath('data.user.password');

        $this->postJson('/logout')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertGuest();
    }

    public function test_student_without_a_registered_parent_phone_cannot_sign_in(): void
    {
        $user = $this->userWithRole(RoleSlug::Student);
        $this->student($user, ['admission_number' => 'SRS/2025/0142']);

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'student',
        ])->assertSessionHasErrors('admission_number');

        $this->assertGuest();
    }

    public function test_pupil_can_set_a_passphrase_using_the_parent_phone_as_the_current_key(): void
    {
        $user = $this->userWithRole(RoleSlug::Student, ['password' => 'secret-pass']);
        $student = $this->student($user, ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);

        $this->actingAs($user)->putJson('/api/v1/me/password', [
            'current_password' => '08030000001',
            'password' => 'my-pupil-key',
            'password_confirmation' => 'my-pupil-key',
        ])->assertOk();

        $this->assertNotNull($student->fresh()->passphrase_set_at);
        $this->assertTrue(Hash::check('my-pupil-key', $user->fresh()->getAuthPassword()));

        $this->post('/logout');

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => 'my-pupil-key',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));
    }
}
