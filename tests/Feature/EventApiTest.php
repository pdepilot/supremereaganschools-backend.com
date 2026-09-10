<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\RoleSlug;
use App\Models\Event;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class EventApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
        $this->seed(PermissionSeeder::class);
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/events')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_create_update_and_delete_event(): void
    {
        Storage::fake('public');
        $admin = $this->admin();

        $created = $this->actingAs($admin)->post('/api/v1/events', [
            'title' => 'Open Day & Campus Tour',
            'summary' => 'Walk the classrooms with admissions.',
            'category' => 'Admissions',
            'starts_at' => now()->addMonth()->setTime(9, 0)->toDateTimeString(),
            'location' => 'Main campus',
            'status' => 'published',
            'is_featured' => '1',
            'cover_image' => UploadedFile::fake()->image('open-day.jpg'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'published')
            ->assertJsonPath('data.is_featured', true)
            ->json('data');

        $this->assertNotEmpty($created['cover_image']);
        $this->assertDatabaseHas('events', [
            'id' => $created['id'],
            'status' => EventStatus::Published->value,
        ]);

        $this->actingAs($admin)->post('/api/v1/events/'.$created['id'], [
            '_method' => 'PUT',
            'title' => 'Open Day & Campus Tour',
            'summary' => 'Updated summary for families.',
            'category' => 'Admissions',
            'starts_at' => now()->addMonth()->setTime(9, 0)->toDateTimeString(),
            'location' => 'Main campus',
            'status' => 'draft',
            'is_featured' => '0',
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $this->actingAs($admin)->deleteJson('/api/v1/events/'.$created['id'])
            ->assertOk();

        $this->assertSoftDeleted('events', ['id' => $created['id']]);
    }

    public function test_content_manager_can_manage_events_teacher_cannot(): void
    {
        $manager = $this->userWithRole(RoleSlug::ContentManager);
        $teacher = $this->userWithRole(RoleSlug::Teacher);

        $this->actingAs($manager)->postJson('/api/v1/events', [
            'title' => 'Sports Day',
            'summary' => 'Inter-house races.',
            'starts_at' => now()->addWeeks(2)->toDateTimeString(),
            'status' => 'published',
        ])->assertCreated();

        $this->actingAs($teacher)->getJson('/api/v1/events')
            ->assertForbidden();
    }

    public function test_public_pages_show_published_upcoming_and_hide_drafts(): void
    {
        Event::query()->create([
            'title' => 'Visible Open Day',
            'slug' => 'visible-open-day',
            'summary' => 'Families welcome on campus.',
            'category' => 'Admissions',
            'starts_at' => now()->addDays(10)->setTime(9, 0),
            'location' => 'Main campus',
            'is_featured' => true,
            'status' => EventStatus::Published,
            'published_at' => now(),
        ]);

        Event::query()->create([
            'title' => 'Hidden Draft Gathering',
            'slug' => 'hidden-draft-gathering',
            'summary' => 'Should not appear publicly.',
            'starts_at' => now()->addDays(12),
            'status' => EventStatus::Draft,
            'published_at' => null,
        ]);

        $this->get('/events')
            ->assertOk()
            ->assertSee('Visible Open Day', false)
            ->assertSee('Families welcome on campus.', false)
            ->assertDontSee('Hidden Draft Gathering', false)
            ->assertDontSee('name="robots" content="noindex,follow"', false)
            ->assertHeaderMissing('X-Robots-Tag');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee(url('/events'), false);

        $this->get('/')
            ->assertOk()
            ->assertSee('Visible Open Day', false)
            ->assertDontSee('Hidden Draft Gathering', false);
    }

    public function test_portal_events_page_loads_for_admin(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/portal/events')
            ->assertOk()
            ->assertSee('Upcoming events', false)
            ->assertSee('portal-events.js', false);
    }
}
