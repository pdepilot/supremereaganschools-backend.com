<?php

namespace Tests\Feature\Portal;

use App\Enums\RoleSlug;
use App\Enums\SessionStatus;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StudentPagesTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public static function pages(): array
    {
        return [
            ['/student/profile', 'student_profile', 'data-profile-personal'],
            ['/student/academics', 'student_academics', 'data-results-body'],
            ['/student/assignments', 'student_assignments', 'data-assignment-list'],
            ['/student/attendance', 'student_attendance', 'data-attendance-body'],
            ['/student/fees', 'student_fees', 'data-fee-invoices'],
            ['/student/timetable', 'student_timetable', 'data-timetable-body'],
            ['/student/materials', 'student_materials', 'data-material-grid'],
            ['/student/messages', 'student_messages', 'data-conversation-items'],
            ['/student/announcements', 'student_announcements', 'data-notice-list'],
            ['/student/settings', 'student_settings', 'data-settings-fields'],
        ];
    }

    #[DataProvider('pages')]
    public function test_inner_pupil_pages_use_the_student_desk(string $path, string $page, string $hook): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student);
        $this->student($pupil);

        $response = $this->actingAs($pupil)
            ->get($path)
            ->assertOk()
            ->assertSee('data-page="'.$page.'"', false)
            ->assertSee('faculty-house', false)
            ->assertSee('staff-desk.css', false)
            ->assertSee('portal-student-pages.js', false)
            ->assertSee($hook, false)
            ->assertSee('data-logout', false)
            ->assertSee('<strong>Log out</strong>', false)
            ->assertSee('href="/student/profile"', false)
            ->assertSee('href="/student/assignments"', false)
            ->assertDontSee('bootstrap.min.css', false)
            ->assertDontSee('parent_student.css', false)
            ->assertDontSee('My Children', false)
            ->assertDontSee('Chiamaka Nwosu', false)
            ->assertDontSee('Mrs. Nwosu', false)
            ->assertDontSee('SRS/2025/0142', false)
            ->assertDontSee('Algebra Exercise', false)
            ->assertDontSee('82%', false);

        if ($page === 'student_settings') {
            $response->assertSee('data-password-form', false)
                ->assertSee('type="password"', false);
        } else {
            $response->assertDontSee('type="password"', false);
        }
    }

    public function test_children_route_sends_the_pupil_home(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student);
        $this->student($pupil);

        $this->actingAs($pupil)
            ->get('/student/children')
            ->assertRedirect('/student');
    }

    public function test_grade_urls_open_the_pupil_mark_book(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student);
        $this->student($pupil);

        $this->actingAs($pupil)->get('/student/grades')->assertRedirect('/student/academics');
        $this->actingAs($pupil)->get('/student/grade')->assertRedirect('/student/academics');

        $this->actingAs($pupil)
            ->get('/student/academics')
            ->assertOk()
            ->assertSee('data-page="student_academics"', false);
    }

    public function test_json_login_returns_the_pupil_to_marks_after_a_refresh_drop(): void
    {
        $user = $this->userWithRole(RoleSlug::Student);
        $student = $this->student($user, ['admission_number' => 'SRS/2025/0142']);
        $this->linkGuardian($this->guardian(null, ['phone' => '08030000001']), $student);

        $this->get('/student/academics')->assertRedirect('/student/login');

        $this->postJson('/login', [
            'admission_number' => 'SRS/2025/0142',
            'password' => '08030000001',
            'portal' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('data.redirect', '/student/academics');
    }

    public function test_guest_is_sent_to_pupil_login(): void
    {
        $this->get('/student/profile')->assertRedirect('/student/login');
        $this->get('/student/fees')->assertRedirect('/student/login');
    }

    public function test_pupil_pages_script_reads_the_live_ledgers(): void
    {
        $js = (string) file_get_contents(public_path('site/JS/portal-student-pages.js'));

        $this->assertStringContainsString('/api/v1/student-desk', $js);
        $this->assertStringContainsString('/api/v1/students/', $js);
        $this->assertStringContainsString('/api/v1/results', $js);
        $this->assertStringContainsString('term_id=', $js);
        $this->assertStringContainsString('data-term-select', $js);
        $this->assertStringContainsString('printResults', $js);
        $this->assertStringContainsString('srsTermReport', $js);
        $this->assertStringContainsString('row.scores', $js);
        $this->assertStringContainsString('assessment_types', $js);
        $this->assertStringContainsString('/api/v1/assignments', $js);
        $this->assertStringContainsString('/api/v1/attendance/summary', $js);
        $this->assertStringContainsString('/api/v1/me/fees/summary', $js);
        $this->assertStringContainsString('/api/v1/me/payments', $js);
        $this->assertStringContainsString('/api/v1/classroom/context', $js);
        $this->assertStringContainsString('/api/v1/timetable?class_section_offering_id=', $js);
        $this->assertStringContainsString('/api/v1/learning-materials', $js);
        $this->assertStringContainsString('/api/v1/documents/', $js);
        $this->assertStringContainsString('/api/v1/announcements', $js);
        $this->assertStringContainsString('/api/v1/messages/recipients', $js);
        $this->assertStringContainsString('/api/v1/conversations', $js);
        $this->assertStringContainsString('/api/v1/me/password', $js);
        $this->assertStringContainsString('pupil passphrase', $js);
        $this->assertStringNotContainsString('type="password"', $js);
        $this->assertStringNotContainsString('/api/v1/payments/checkout', $js);
    }

    public function test_unenrolled_pupil_gets_empty_results_not_a_missing_page(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student);
        $this->student($pupil);

        $this->actingAs($pupil)
            ->getJson('/api/v1/results')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.results', [])
            ->assertJsonPath('data.average', null)
            ->assertJsonPath('data.enrollment_id', null);
    }

    public function test_pupil_results_include_each_recorded_paper(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $session = $this->academicSession(['status' => SessionStatus::Active]);
        $term = $this->termFor($session, ['status' => SessionStatus::Active]);
        $this->settings([
            'current_academic_session_id' => $session->id,
            'current_term_id' => $term->id,
        ]);
        $offering = $this->offering(session: $session);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $user = $this->userWithRole(RoleSlug::Student);
        $pupil = $this->student($user);
        $enrollment = $this->enroll($pupil, $offering);

        foreach ([
            ['first_ca', 12],
            ['second_ca', 14],
            ['examination', 66],
        ] as [$kind, $score]) {
            $this->actingAs($this->admin())->postJson('/api/v1/grades', [
                'enrollment_id' => $enrollment->id,
                'subject_id' => $subject->id,
                'assessment_type_id' => $catalogue['types'][$kind]->id,
                'term_id' => $term->id,
                'score' => $score,
            ])->assertCreated();
        }

        $this->actingAs($user)
            ->get('/student/academics')
            ->assertOk()
            ->assertSee('data-results-head', false)
            ->assertSee('data-session-select', false)
            ->assertSee('data-term-select', false)
            ->assertSee('data-report-sheet', false)
            ->assertSee('portal-report-sheet.js', false)
            ->assertSee('First C.A.', false);

        $this->actingAs($user)
            ->getJson('/api/v1/results')
            ->assertOk()
            ->assertJsonPath('data.results.0.subject_name', 'Mathematics')
            ->assertJsonPath('data.results.0.scores.0.name', 'First CA')
            ->assertJsonPath('data.results.0.scores.0.score', 12)
            ->assertJsonPath('data.results.0.scores.1.score', 14)
            ->assertJsonPath('data.results.0.scores.2.score', 66)
            ->assertJsonPath('data.results.0.ca_total', 26)
            ->assertJsonPath('data.results.0.exam_score', 66)
            ->assertJsonPath('data.results.0.total', 92);
    }

    public function test_this_term_shows_marks_saved_on_the_live_term_even_if_the_class_is_on_a_previous_session(): void
    {
        $catalogue = $this->assessmentCatalogue();
        $homeSession = $this->academicSession([
            'name' => '2025/2026',
            'status' => SessionStatus::Archived,
        ]);
        $homeTerm = $this->termFor($homeSession, ['name' => 'First Term']);
        $liveSession = $this->academicSession([
            'name' => '2026/2027',
            'starts_on' => '2026-09-08',
            'ends_on' => '2027-07-24',
            'status' => SessionStatus::Active,
        ]);
        $liveTerm = $this->termFor($liveSession, [
            'name' => 'First Term',
            'status' => SessionStatus::Active,
        ]);
        $this->settings([
            'current_academic_session_id' => $liveSession->id,
            'current_term_id' => $liveTerm->id,
        ]);

        $offering = $this->offering(session: $homeSession);
        $subject = $this->subject(['name' => 'Mathematics', 'code' => 'MTH']);
        $this->subjectOffering($offering, $subject);
        $user = $this->userWithRole(RoleSlug::Student);
        $pupil = $this->student($user);
        $enrollment = $this->enroll($pupil, $offering);

        foreach ([
            ['first_ca', 12],
            ['second_ca', 14],
        ] as [$kind, $score]) {
            $this->actingAs($this->admin())->postJson('/api/v1/grades', [
                'enrollment_id' => $enrollment->id,
                'subject_id' => $subject->id,
                'assessment_type_id' => $catalogue['types'][$kind]->id,
                'term_id' => $liveTerm->id,
                'score' => $score,
            ])->assertCreated();
        }

        $this->actingAs($user)
            ->getJson('/api/v1/results')
            ->assertOk()
            ->assertJsonPath('data.session_name', '2026/2027')
            ->assertJsonPath('data.term_name', 'First Term')
            ->assertJsonPath('data.term_id', $liveTerm->id)
            ->assertJsonPath('data.results.0.subject_name', 'Mathematics')
            ->assertJsonPath('data.results.0.scores.0.score', 12)
            ->assertJsonPath('data.results.0.scores.1.score', 14)
            ->assertJsonPath('data.results.0.scores.2.score', null)
            ->assertJsonPath('data.results.0.total', 26)
            ->assertJsonPath('data.periods.0.session_name', '2026/2027')
            ->assertJsonPath('data.periods.0.name', 'First Term')
            ->assertJsonPath('data.periods.0.is_current', true);

        $this->actingAs($user)
            ->getJson('/api/v1/results?term_id='.$liveTerm->id)
            ->assertOk()
            ->assertJsonPath('data.term_id', $liveTerm->id)
            ->assertJsonPath('data.results.0.scores.0.score', 12);

        $this->actingAs($user)
            ->getJson('/api/v1/results?term_id='.$homeTerm->id)
            ->assertOk()
            ->assertJsonPath('data.term_id', $homeTerm->id)
            ->assertJsonPath('data.session_name', '2025/2026')
            ->assertJsonPath('data.results.0.subject_name', 'Mathematics')
            ->assertJsonPath('data.results.0.scores.0.score', null)
            ->assertJsonPath('data.results.0.total', null);
    }

    public function test_fees_page_does_not_offer_checkout(): void
    {
        $pupil = $this->userWithRole(RoleSlug::Student);
        $this->student($pupil);

        $this->actingAs($pupil)
            ->get('/student/fees')
            ->assertOk()
            ->assertSee('this desk does not take payment', false)
            ->assertDontSee('Pay now', false)
            ->assertDontSee('checkout', false);
    }
}
