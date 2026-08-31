<?php

namespace Tests\Feature\Portal;

use App\Enums\RoleSlug;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class StaffHousePagesTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function pages(): array
    {
        return [
            ['/staff/students', 'students', 'portal-people.js', 'data-assigned-students'],
            ['/staff/attendance', 'attendance', 'portal-attendance.js', 'attendanceDate'],
            ['/staff/assignments', 'assignments', 'portal-classroom.js', 'assignmentForm'],
            ['/staff/grades', 'grades', 'portal-grades.js', 'gradesBody'],
            ['/staff/reports', 'reports', 'portal-staff-reports.js', 'data-generate-report'],
            ['/staff/timetable', 'timetable', 'portal-classroom.js', 'classFilter'],
            ['/staff/materials', 'materials', 'portal-classroom.js', 'materials-grid'],
            ['/staff/messages', 'messages', 'portal-classroom.js', 'conversationList'],
            ['/staff/announcements', 'announcements', 'portal-classroom.js', 'announcementGrid'],
            ['/staff/profile', 'profile', 'portal-staff-chrome.js', 'data-account-form'],
            ['/staff/settings', 'settings', 'portal-staff-chrome.js', 'data-password-form'],
        ];
    }

    #[DataProvider('pages')]
    public function test_inner_staff_pages_use_the_faculty_house(string $path, string $page, string $script, string $hook): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($teacher)
            ->get($path)
            ->assertOk()
            ->assertSee('faculty-house', false)
            ->assertSee('staff-desk.css', false)
            ->assertSee('portal-staff-chrome.js', false)
            ->assertSee('data-page="'.$page.'"', false)
            ->assertSee($script, false)
            ->assertSee($hook, false)
            ->assertSee('href="/staff"', false)
            ->assertSee('href="/staff/attendance"', false)
            ->assertSee('data-logout', false)
            ->assertSee('<strong>Log out</strong>', false)
            ->assertDontSee('Mrs. Okafor', false)
            ->assertDontSee('91%', false)
            ->assertDontSee('Living Word', false);
    }

    public function test_grade_short_urls_open_the_mark_book(): void
    {
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($teacher)
            ->get('/staff/grade')
            ->assertRedirect('/staff/grades');

        $this->actingAs($teacher)
            ->get('/staff/grades')
            ->assertOk()
            ->assertSee('data-page="grades"', false)
            ->assertSee('portal-grades.js', false);
    }
}
