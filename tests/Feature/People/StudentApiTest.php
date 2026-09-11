<?php

namespace Tests\Feature\People;

use App\Enums\EnrollmentStatus;
use App\Enums\Gender;
use App\Enums\GuardianRelationship;
use App\Enums\InvoiceStatus;
use App\Enums\RoleSlug;
use App\Enums\StudentStatus;
use App\Enums\UserStatus;
use App\Mail\SchoolCircularMail;
use App\Models\Invoice;
use App\Models\StudentProfile;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StudentApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    private FilesystemAdapter $files;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->files = Storage::fake('local');
    }

    public function test_admin_can_create_and_update_a_student(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();

        $create = $this->registerPupil([
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
            'gender' => Gender::Female->value,
            'class_section_id' => $offering->class_section_id,
            'academic_session_id' => $offering->academic_session_id,
            'status' => StudentStatus::Active->value,
        ], $admin);

        $create->assertCreated()
            ->assertJsonPath('data.admission_number', 'SRS/2025/0142')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.gender', 'female')
            ->assertJsonPath('data.date_of_birth', '2016-03-14')
            ->assertJsonPath('data.has_photo', true)
            ->assertJsonPath('data.photo_url', '/api/v1/students/'.$create->json('data.id').'/photo')
            ->assertJsonMissingPath('data.password');

        $student = StudentProfile::query()->where('admission_number', 'SRS/2025/0142')->firstOrFail();

        $this->assertNotNull($student->user->getAuthPassword());
        $this->assertFalse(Hash::check('secret-pass', $student->user->getAuthPassword()));
        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $student->id,
            'class_section_offering_id' => $offering->id,
        ]);
        $this->assertNotNull($student->photo_path);
        $this->files->assertExists($student->photo_path);

        $this->actingAs($admin)->get('/api/v1/students/'.$student->id.'/photo')
            ->assertOk();

        $this->actingAs($admin)->putJson('/api/v1/students/'.$student->id, [
            'status' => StudentStatus::Inactive->value,
            'other_names' => 'Ada',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.other_names', 'Ada');
    }

    public function test_auto_admission_numbers_are_unique_per_wing(): void
    {
        $year = (int) now('Africa/Lagos')->year;
        $session = $this->academicSession(['name' => $year.'/'.($year + 1)]);
        $campus = $this->campus();

        $nursery = $this->level(['name' => 'Nursery', 'slug' => 'nursery', 'sort_order' => 2]);
        $primary = $this->level(['name' => 'Primary', 'slug' => 'primary', 'sort_order' => 3]);
        $jss = $this->level(['name' => 'Junior Secondary', 'slug' => 'jss', 'sort_order' => 4]);

        $nurserySection = $this->section(
            $this->schoolClass($nursery, ['name' => 'Nursery 1', 'short_code' => 'N1']),
            ['arm' => 'A', 'name' => 'Nursery 1 A'],
        );
        $primarySection = $this->section(
            $this->schoolClass($primary, ['name' => 'Basic 1', 'short_code' => 'B1']),
            ['arm' => 'A', 'name' => 'Basic 1 A'],
        );
        $jssSection = $this->section(
            $this->schoolClass($jss, ['name' => 'JSS 1', 'short_code' => 'J1']),
            ['arm' => 'Reagan', 'name' => 'JSS 1 Reagan'],
        );

        $nurseryOffering = $this->offering($nurserySection, $session, $campus);
        $primaryOffering = $this->offering($primarySection, $session, $campus);
        $jssOffering = $this->offering($jssSection, $session, $campus);

        $this->registerPupil([
            'surname' => 'Nursery',
            'first_name' => 'One',
            'gender' => Gender::Female->value,
            'class_section_id' => $nurseryOffering->class_section_id,
            'academic_session_id' => $nurseryOffering->academic_session_id,
        ])->assertCreated()->assertJsonPath('data.admission_number', 'SRS/NUR/'.$year.'/0001');

        $this->registerPupil([
            'surname' => 'Primary',
            'first_name' => 'One',
            'gender' => Gender::Male->value,
            'class_section_id' => $primaryOffering->class_section_id,
            'academic_session_id' => $primaryOffering->academic_session_id,
        ])->assertCreated()->assertJsonPath('data.admission_number', 'SRS/PRI/'.$year.'/0001');

        $this->registerPupil([
            'surname' => 'Secondary',
            'first_name' => 'One',
            'gender' => Gender::Female->value,
            'class_section_id' => $jssOffering->class_section_id,
            'academic_session_id' => $jssOffering->academic_session_id,
        ])->assertCreated()->assertJsonPath('data.admission_number', 'SRS/SEC/'.$year.'/0001');

        $this->registerPupil([
            'surname' => 'Nursery',
            'first_name' => 'Two',
            'gender' => Gender::Male->value,
            'class_section_id' => $nurseryOffering->class_section_id,
            'academic_session_id' => $nurseryOffering->academic_session_id,
        ])->assertCreated()->assertJsonPath('data.admission_number', 'SRS/NUR/'.$year.'/0002');
    }

    public function test_admin_can_register_a_pupil_with_a_primary_guardian(): void
    {
        Mail::fake();

        $admin = $this->admin();
        $offering = $this->offering();

        $this->registerPupil([
            'admission_number' => 'SRS/2025/0301',
            'surname' => 'Nwosu',
            'first_name' => 'Ada',
            'gender' => Gender::Female->value,
            'nationality' => 'Nigerian',
            'state_of_origin' => 'Imo',
            'home_address' => '12 Wetheral Road, Owerri',
            'blood_group' => 'O+',
            'class_section_id' => $offering->class_section_id,
            'academic_session_id' => $offering->academic_session_id,
            'guardian' => [
                'full_name' => 'Mrs. Nwosu',
                'relationship' => 'mother',
                'phone' => '08031112233',
                'email' => 'nwosu.parent@school.test',
                'password' => 'parent-pass',
                'occupation' => 'Trader',
            ],
        ], $admin)
            ->assertCreated()
            ->assertJsonPath('data.admission_number', 'SRS/2025/0301')
            ->assertJsonPath('data.primary_guardian', 'Mrs. Nwosu')
            ->assertJsonPath('data.guardians.0.full_name', 'Mrs. Nwosu')
            ->assertJsonPath('data.guardians.0.relationship', 'mother')
            ->assertJsonPath('data.guardians.0.is_primary', true)
            ->assertJsonPath('data.guardians.0.has_login', true)
            ->assertJsonPath('data.date_of_birth', '2016-03-14')
            ->assertJsonPath('data.home_address', '12 Wetheral Road, Owerri')
            ->assertJsonPath('data.blood_group', 'O+')
            ->assertJsonMissingPath('data.guardians.0.password');

        $student = StudentProfile::query()->where('admission_number', 'SRS/2025/0301')->firstOrFail();
        $this->assertDatabaseHas('guardian_student', [
            'student_profile_id' => $student->id,
            'is_primary' => true,
            'relationship' => 'mother',
        ]);
        $this->assertTrue(Hash::check('parent-pass', $student->guardians()->firstOrFail()->user->getAuthPassword()));

        Mail::assertSent(SchoolCircularMail::class, function (SchoolCircularMail $mail) {
            return $mail->hasTo('nwosu.parent@school.test')
                && str_contains($mail->subjectLine, 'SRS/2025/0301')
                && str_contains($mail->bodyHtml, '08031112233')
                && str_contains($mail->bodyHtml, '/parent/login')
                && str_contains($mail->bodyHtml, '/student/login');
        });

        $this->post('/logout');

        $this->post('/login', [
            'admission_number' => 'SRS/2025/0301',
            'password' => '08031112233',
            'portal' => 'student',
        ])->assertRedirect(route('student.home'));
    }

    public function test_registration_email_is_skipped_when_guardian_has_no_mailbox(): void
    {
        Mail::fake();

        $this->registerPupil([
            'admission_number' => 'SRS/2025/0302',
            'surname' => 'Eze',
            'first_name' => 'Kosi',
            'gender' => Gender::Male->value,
            'guardian' => [
                'full_name' => 'Mr. Eze',
                'relationship' => 'father',
                'phone' => '08035556677',
            ],
        ])->assertCreated();

        Mail::assertNothingSent();
    }

    public function test_same_guardian_email_can_register_multiple_pupils(): void
    {
        Mail::fake();

        $first = $this->registerPupil([
            'admission_number' => 'SRS/PRI/2026/0101',
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
            'gender' => Gender::Female->value,
            'guardian' => [
                'full_name' => 'Mrs. Okafor',
                'relationship' => 'mother',
                'phone' => '08031112233',
                'email' => 'okafor.family@school.test',
                'password' => 'parent-pass-1',
            ],
        ])->assertCreated();

        $second = $this->registerPupil([
            'admission_number' => 'SRS/PRI/2026/0102',
            'surname' => 'Okafor',
            'first_name' => 'Daniel',
            'gender' => Gender::Male->value,
            'guardian' => [
                'full_name' => 'Mrs. Okafor',
                'relationship' => 'mother',
                'phone' => '08031112233',
                'email' => 'okafor.family@school.test',
            ],
        ])->assertCreated();

        $firstId = (int) $first->json('data.id');
        $secondId = (int) $second->json('data.id');
        $guardianId = (int) $first->json('data.guardians.0.id');

        $this->assertSame($guardianId, (int) $second->json('data.guardians.0.id'));
        $this->assertDatabaseCount('guardian_profiles', 1);
        $this->assertSame(1, User::query()->where('email', 'okafor.family@school.test')->count());
        $this->assertDatabaseHas('guardian_student', [
            'guardian_profile_id' => $guardianId,
            'student_profile_id' => $firstId,
            'is_primary' => 1,
        ]);
        $this->assertDatabaseHas('guardian_student', [
            'guardian_profile_id' => $guardianId,
            'student_profile_id' => $secondId,
            'is_primary' => 1,
        ]);
    }

    public function test_admin_can_register_pupil_into_class_arm_without_preopened_form(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $this->campus();
        $section = $this->section();

        $this->assertDatabaseMissing('class_section_offerings', [
            'class_section_id' => $section->id,
            'academic_session_id' => $session->id,
        ]);

        $this->registerPupil([
            'admission_number' => 'SRS/2026/0101',
            'surname' => 'Okoro',
            'first_name' => 'Ifeoma',
            'gender' => Gender::Female->value,
            'class_section_id' => $section->id,
            'academic_session_id' => $session->id,
        ], $admin)->assertCreated();

        $student = StudentProfile::query()->where('admission_number', 'SRS/2026/0101')->firstOrFail();
        $this->assertDatabaseHas('class_section_offerings', [
            'class_section_id' => $section->id,
            'academic_session_id' => $session->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'student_profile_id' => $student->id,
            'academic_session_id' => $session->id,
        ]);
    }

    public function test_admin_can_view_a_registered_pupil_record(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $student = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0410',
            'surname' => 'Okeke',
            'first_name' => 'Chidi',
            'date_of_birth' => '2015-06-22',
            'nationality' => 'Nigerian',
            'state_of_origin' => 'Imo',
            'lga' => 'Owerri Municipal',
            'home_address' => '8 Wetheral Road, Owerri',
            'blood_group' => 'O+',
            'genotype' => 'AA',
            'previous_school' => 'St. Mary\'s Nursery',
        ]);
        $this->enroll($student, $offering);
        $this->linkGuardian($this->guardian(null, [
            'full_name' => 'Mr. Okeke',
            'phone' => '08030004444',
            'email' => 'okeke.view@school.test',
            'occupation' => 'Engineer',
        ]), $student, ['relationship' => GuardianRelationship::Father]);

        $this->actingAs($admin)
            ->getJson('/api/v1/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('data.admission_number', 'SRS/2025/0410')
            ->assertJsonPath('data.full_name', 'Okeke Chidi')
            ->assertJsonPath('data.home_address', '8 Wetheral Road, Owerri')
            ->assertJsonPath('data.blood_group', 'O+')
            ->assertJsonPath('data.genotype', 'AA')
            ->assertJsonPath('data.previous_school', 'St. Mary\'s Nursery')
            ->assertJsonPath('data.session_name', $offering->academicSession->name)
            ->assertJsonPath('data.campus_name', $offering->campus->name)
            ->assertJsonPath('data.guardians.0.full_name', 'Mr. Okeke')
            ->assertJsonPath('data.guardians.0.phone', '08030004444')
            ->assertJsonPath('data.guardians.0.is_primary', true);
    }

    public function test_admin_can_revise_a_registered_pupil_and_guardian(): void
    {
        $admin = $this->admin();
        $offering = $this->offering();
        $student = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0302',
            'surname' => 'Okeke',
            'first_name' => 'Chidi',
        ]);
        $this->enroll($student, $offering);
        $this->linkGuardian($this->guardian(null, [
            'full_name' => 'Mr. Okeke',
            'phone' => '08030001111',
            'email' => 'okeke.parent@school.test',
        ]), $student, ['relationship' => GuardianRelationship::Father]);

        $this->actingAs($admin)->putJson('/api/v1/students/'.$student->id, [
            'other_names' => 'Ifeanyi',
            'guardian' => [
                'full_name' => 'Mr. Chukwuemeka Okeke',
                'relationship' => 'father',
                'phone' => '08039998877',
                'occupation' => 'Engineer',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.other_names', 'Ifeanyi')
            ->assertJsonPath('data.primary_guardian', 'Mr. Chukwuemeka Okeke')
            ->assertJsonPath('data.guardians.0.phone', '08039998877')
            ->assertJsonPath('data.guardians.0.occupation', 'Engineer')
            ->assertJsonPath('data.guardians.0.relationship', 'father');
    }

    public function test_admin_roll_includes_live_form_guardian_and_fee_state(): void
    {
        $admin = $this->admin();
        $session = $this->academicSession();
        $term = $this->termFor($session);
        $offering = $this->offering(null, $session);
        $student = $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);
        $this->enroll($student, $offering);
        $this->linkGuardian($this->guardian(), $student);

        Invoice::query()->create([
            'number' => 'INV/2025/0200',
            'student_profile_id' => $student->id,
            'academic_session_id' => $session->id,
            'term_id' => $term->id,
            'status' => InvoiceStatus::Partial,
            'total_kobo' => 200000,
            'paid_kobo' => 50000,
        ]);

        $bare = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0888',
            'surname' => 'Adeleke',
            'first_name' => 'Tunde',
            'status' => StudentStatus::Pending,
        ]);

        $this->actingAs($admin)
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment([
                'admission_number' => 'SRS/2025/0142',
                'current_form' => $offering->classSection->name,
                'level_id' => $offering->classSection->schoolClass->level_id,
                'level_name' => $offering->classSection->schoolClass->level->name,
                'wing' => 'secondary',
                'primary_guardian' => 'Mrs. Okafor',
                'fee_state' => 'partial',
                'fee_label' => '₦1,500 due',
            ])
            ->assertJsonFragment([
                'admission_number' => $bare->admission_number,
                'fee_state' => 'none',
                'fee_label' => 'No invoice',
                'current_form' => null,
            ]);
    }

    public function test_duplicate_admission_number_is_rejected(): void
    {
        $this->student($this->userWithRole(RoleSlug::Student), ['admission_number' => 'SRS/2025/0142']);

        $this->registerPupil([
            'admission_number' => 'SRS/2025/0142',
            'surname' => 'Other',
            'first_name' => 'Pupil',
            'gender' => Gender::Male->value,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('admission_number');
    }

    public function test_a_pupil_cannot_be_registered_without_a_photograph(): void
    {
        $this->actingAs($this->admin())->postJson('/api/v1/students', [
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
            'gender' => Gender::Female->value,
            'date_of_birth' => '2016-03-14',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('photo');
    }

    public function test_a_pupil_cannot_be_registered_without_a_date_of_birth(): void
    {
        $this->registerPupil([
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
            'gender' => Gender::Female->value,
            'date_of_birth' => null,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_birth');
    }

    public function test_admin_can_register_a_pupil_with_a_captured_photograph(): void
    {
        $photo = $this->pupilPhoto('capture.jpg');
        $payload = 'data:image/jpeg;base64,'.base64_encode((string) file_get_contents($photo->getRealPath()));

        $this->actingAs($this->admin())->postJson('/api/v1/students', [
            'admission_number' => 'SRS/2025/0404',
            'surname' => 'Eze',
            'first_name' => 'Ngozi',
            'gender' => Gender::Female->value,
            'date_of_birth' => '2016-08-02',
            'photo_base64' => $payload,
        ])
            ->assertCreated()
            ->assertJsonPath('data.has_photo', true)
            ->assertJsonPath('data.admission_number', 'SRS/2025/0404');

        $student = StudentProfile::query()->where('admission_number', 'SRS/2025/0404')->firstOrFail();
        $this->files->assertExists($student->photo_path);
    }

    public function test_invalid_gender_is_rejected(): void
    {
        $this->registerPupil([
            'surname' => 'Okafor',
            'first_name' => 'Chiamaka',
            'gender' => 'unknown',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('gender');
    }

    public function test_health_fields_are_hidden_from_parents(): void
    {
        $student = $this->student();
        $student->update(['blood_group' => 'O+', 'medical_notes' => 'Asthma']);
        $parentUser = $this->userWithRole(RoleSlug::Parent);
        $guardian = $this->guardian($parentUser);
        $this->linkGuardian($guardian, $student);

        $this->actingAs($parentUser)->getJson('/api/v1/students/'.$student->id)
            ->assertOk()
            ->assertJsonMissingPath('data.blood_group')
            ->assertJsonMissingPath('data.medical_notes')
            ->assertJsonMissingPath('data.password');
    }

    public function test_pupil_can_read_their_own_folder_including_health(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student);
        $own = $this->student($pupil, [
            'home_address' => '12 Ikorodu Road, Lagos',
            'blood_group' => 'O+',
            'medical_notes' => 'Asthma',
        ]);
        $other = $this->student();

        $this->actingAs($pupil)
            ->getJson('/api/v1/students/'.$own->id)
            ->assertOk()
            ->assertJsonPath('data.id', $own->id)
            ->assertJsonPath('data.home_address', '12 Ikorodu Road, Lagos')
            ->assertJsonPath('data.blood_group', 'O+')
            ->assertJsonPath('data.medical_notes', 'Asthma')
            ->assertJsonMissingPath('data.password');

        $this->actingAs($pupil)
            ->getJson('/api/v1/students/'.$other->id)
            ->assertForbidden();
    }

    public function test_admin_can_remove_a_pupil_from_the_roll(): void
    {
        $admin = $this->admin();
        $parentUser = $this->userWithRole(RoleSlug::Parent, ['email' => 'reuse.parent@example.test']);
        $studentUser = $this->userWithRole(RoleSlug::Student, ['email' => 'reuse.pupil@example.test']);
        $student = $this->student($studentUser, [
            'admission_number' => 'SRS/2025/7788',
            'phone' => '08031112222',
            'email' => 'pupil.contact@example.test',
        ]);
        $guardian = $this->guardian($parentUser, [
            'phone' => '08039998888',
            'email' => 'reuse.parent@example.test',
        ]);
        $this->linkGuardian($guardian, $student);

        $this->actingAs($admin)
            ->deleteJson('/api/v1/students/'.$student->id)
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSoftDeleted('student_profiles', ['id' => $student->id]);
        $this->assertSoftDeleted('guardian_profiles', ['id' => $guardian->id]);

        $archived = StudentProfile::withTrashed()->findOrFail($student->id);
        $this->assertNull($archived->phone);
        $this->assertNull($archived->email);
        $this->assertNull($archived->user_id);
        $this->assertNotSame('SRS/2025/7788', $archived->admission_number);

        $this->assertDatabaseMissing('users', ['email' => 'reuse.pupil@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'reuse.parent@example.test']);

        $this->assertDatabaseHas('rbac_audit_logs', [
            'actor_id' => $admin->id,
            'action' => 'pupil.deleted',
            'subject_type' => StudentProfile::class,
            'subject_id' => $student->id,
        ]);

        $audit = \App\Models\RbacAuditLog::query()
            ->where('action', 'pupil.deleted')
            ->where('subject_id', $student->id)
            ->firstOrFail();
        $this->assertSame('SRS/2025/7788', $audit->meta['admission_number'] ?? null);
        $this->assertSame('08039998888', $audit->meta['guardians'][0]['phone'] ?? null);
        $this->assertSame('reuse.parent@example.test', $audit->meta['guardians'][0]['email'] ?? null);

        // Same email/phone/admission number can be registered again.
        $replacementUser = $this->userWithRole(RoleSlug::Student, ['email' => 'reuse.pupil@example.test']);
        $replacement = $this->student($replacementUser, [
            'admission_number' => 'SRS/2025/7788',
            'phone' => '08031112222',
            'email' => 'pupil.contact@example.test',
        ]);
        $this->assertNotNull($replacement->id);

        $this->actingAs($admin)
            ->getJson('/api/v1/students')
            ->assertOk()
            ->assertJsonMissing(['id' => $student->id]);
    }

    public function test_admin_can_remove_an_enrolled_pupil_without_erasing_history(): void
    {
        $admin = $this->admin();
        $student = $this->student();
        $enrollment = $this->enroll($student, $this->offering());

        $this->actingAs($admin)
            ->deleteJson('/api/v1/students/'.$student->id)
            ->assertOk();

        $this->assertSoftDeleted('student_profiles', ['id' => $student->id]);
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'student_profile_id' => $student->id,
            'status' => EnrollmentStatus::Withdrawn->value,
        ]);
    }

    public function test_deleting_one_pupil_keeps_shared_guardian_contacts(): void
    {
        $admin = $this->admin();
        $parentUser = $this->userWithRole(RoleSlug::Parent, ['email' => 'shared.parent@example.test']);
        $guardian = $this->guardian($parentUser, [
            'phone' => '08035556666',
            'email' => 'shared.parent@example.test',
        ]);

        $first = $this->student($this->userWithRole(RoleSlug::Student, ['email' => 'child.one@example.test']));
        $second = $this->student($this->userWithRole(RoleSlug::Student, ['email' => 'child.two@example.test']));
        $this->linkGuardian($guardian, $first);
        $this->linkGuardian($guardian, $second, ['is_primary' => false]);

        $this->actingAs($admin)->deleteJson('/api/v1/students/'.$first->id)->assertOk();

        $this->assertSoftDeleted('student_profiles', ['id' => $first->id]);
        $this->assertNotSoftDeleted('guardian_profiles', ['id' => $guardian->id]);
        $this->assertDatabaseHas('guardian_profiles', [
            'id' => $guardian->id,
            'phone' => '08035556666',
            'email' => 'shared.parent@example.test',
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $parentUser->id,
            'email' => 'shared.parent@example.test',
        ]);
        $this->assertDatabaseHas('guardian_student', [
            'guardian_profile_id' => $guardian->id,
            'student_profile_id' => $second->id,
        ]);
    }

    public function test_admin_can_suspend_and_reinstate_a_pupil(): void
    {
        $admin = $this->admin();
        $user = $this->userWithRole(RoleSlug::Student, ['password' => 'secret-pass']);
        $student = $this->student($user, ['admission_number' => 'SRS/2025/0142']);
        $enrollment = $this->enroll($student, $this->offering());

        $this->actingAs($admin)
            ->postJson('/api/v1/students/'.$student->id.'/suspend')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Pupil suspended.')
            ->assertJsonPath('data.status', 'inactive')
            ->assertJsonPath('data.account_status', 'suspended');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'status' => UserStatus::Suspended->value,
        ]);
        $this->assertDatabaseHas('student_profiles', [
            'id' => $student->id,
            'status' => StudentStatus::Inactive->value,
        ]);
        $this->assertDatabaseHas('enrollments', [
            'id' => $enrollment->id,
            'status' => EnrollmentStatus::Active->value,
        ]);

        $this->post('/logout');
        $this->assertGuest();

        $this->from('/portal/login')->post('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => 'secret-pass',
            'portal' => 'student',
        ])
            ->assertRedirect('/portal/login')
            ->assertSessionHasErrors('admission_number');

        $this->actingAs($admin)
            ->postJson('/api/v1/students/'.$student->id.'/reinstate')
            ->assertOk()
            ->assertJsonPath('message', 'Pupil reinstated.')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.account_status', 'active');
    }

    public function test_admin_can_filter_the_roll_by_school_wing(): void
    {
        $session = $this->academicSession();
        $campus = $this->campus();
        $nurseryLevel = $this->level(['name' => 'Nursery', 'slug' => 'nursery', 'sort_order' => 1]);
        $nurseryOffering = $this->offering(
            $this->section($this->schoolClass($nurseryLevel, ['name' => 'KG 1', 'short_code' => 'KG1'])),
            $session,
            $campus,
        );
        $secondaryOffering = $this->offering(null, $session, $campus);

        $nurseryPupil = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0101',
            'surname' => 'Nwosu',
            'first_name' => 'Ada',
        ]);
        $secondaryPupil = $this->student($this->userWithRole(RoleSlug::Student), [
            'admission_number' => 'SRS/2025/0202',
            'surname' => 'Okeke',
            'first_name' => 'Chidi',
        ]);
        $this->enroll($nurseryPupil, $nurseryOffering);
        $this->enroll($secondaryPupil, $secondaryOffering);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/students?wing=nursery')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.admission_number', 'SRS/2025/0101')
            ->assertJsonPath('data.0.wing', 'nursery');

        $this->actingAs($this->admin())
            ->getJson('/api/v1/students?wing=secondary')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.admission_number', 'SRS/2025/0202')
            ->assertJsonPath('data.0.wing', 'secondary');

        $this->actingAs($this->admin())
            ->getJson('/api/v1/students?wing=college')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('wing');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function registerPupil(array $payload, $as = null)
    {
        if (! isset($payload['photo']) && ! isset($payload['photo_base64'])) {
            $payload['photo'] = $this->pupilPhoto();
        }
        if (! array_key_exists('date_of_birth', $payload)) {
            $payload['date_of_birth'] = '2016-03-14';
        }

        return $this->actingAs($as ?? $this->admin())
            ->post('/api/v1/students', $payload, ['Accept' => 'application/json']);
    }

    private function pupilPhoto(string $name = 'passport.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 240, 320);
    }
}
