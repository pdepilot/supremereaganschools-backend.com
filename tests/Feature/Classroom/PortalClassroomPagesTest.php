<?php

namespace Tests\Feature\Classroom;

use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class PortalClassroomPagesTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_admin_classroom_pages_load_with_backend_hooks(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/announcements')
            ->assertOk()
            ->assertSee('data-page="announcements"', false)
            ->assertSee('data-notice-board', false)
            ->assertSee('data-notice-form', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('noticeTitle', false)
            ->assertSee('portal-classroom.js', false)
            ->assertDontSee('NOTE-044', false)
            ->assertDontSee('Mid-term examinations', false)
            ->assertDontSee('data-count="4"', false)
            ->assertDontSee('data-count="1248"', false);

        $this->actingAs($admin)
            ->get('/portal/timetable')
            ->assertOk()
            ->assertSee('data-page="timetable"', false)
            ->assertSee('classFilter', false)
            ->assertSee('data-bell-form', false)
            ->assertSee('data-bell-new-subject', false)
            ->assertSee('data-bell-grid', false)
            ->assertSee('data-desk-alert', false)
            ->assertSee('portal-classroom.js', false)
            ->assertDontSee('Mrs. Eze', false)
            ->assertDontSee('Primary 4B', false)
            ->assertDontSee('JSS 2A · First term', false);

        $classroomJs = (string) file_get_contents(public_path('site/JS/portal-classroom.js'));
        $this->assertStringContainsString('data-bell-form', $classroomJs);
        $this->assertStringContainsString('confirmDesk', $classroomJs);
        $this->assertStringContainsString('/api/v1/timetable', $classroomJs);
        $this->assertStringContainsString('/api/v1/subjects', $classroomJs);
        $this->assertStringContainsString('/api/v1/subject-offerings', $classroomJs);
        $this->assertStringContainsString('New subject', $classroomJs);
        $this->assertStringContainsString('data-notice-form', $classroomJs);
        $this->assertStringContainsString('ANNOUNCEMENT_POLL_MS', $classroomJs);
        $this->assertStringContainsString('/api/v1/announcements/', $classroomJs);
        $this->assertStringContainsString('visibilitychange', $classroomJs);
    }
}
