<?php

namespace Tests\Feature\Classroom;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementStatus;
use App\Enums\RoleSlug;
use App\Models\Announcement;
use App\Models\TimetableSlot;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class AnnouncementApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/announcements')
            ->assertUnauthorized()
            ->assertJsonPath('success', false);
    }

    public function test_admin_and_teacher_can_publish_and_parents_see_matching_audience(): void
    {
        $admin = $this->admin();
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($teacher);
        $parent = $this->userWithRole(RoleSlug::Parent);
        $studentUser = $this->userWithRole(RoleSlug::Student);
        $this->enroll($this->student($studentUser), $this->offering());

        $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'PTA briefing',
            'body' => 'Thursday in the hall.',
            'audience' => 'Parents',
            'category' => 'General',
        ])
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.audience', 'parents')
            ->assertJsonPath('data.status', 'published');

        $this->actingAs($teacher)->postJson('/api/v1/announcements', [
            'title' => 'Staff briefing',
            'body' => 'Meet in the staff room.',
            'audience' => 'All Staff',
            'category' => 'Academic',
        ])->assertCreated();

        $this->actingAs($parent)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['title' => 'PTA briefing'])
            ->assertJsonMissing(['title' => 'Staff briefing']);

        $this->actingAs($teacher)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Staff briefing']);

        $this->actingAs($studentUser)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonMissing(['title' => 'PTA briefing']);
    }

    public function test_draft_is_hidden_from_other_staff(): void
    {
        $author = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($author);
        $other = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($other);

        $this->actingAs($author)->postJson('/api/v1/announcements', [
            'title' => 'Unfinished circular',
            'body' => 'Still drafting.',
            'audience' => 'staff',
            'status' => 'draft',
        ])->assertCreated();

        $this->actingAs($author)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Unfinished circular']);

        $this->actingAs($other)->getJson('/api/v1/announcements')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Unfinished circular']);
    }

    public function test_parent_cannot_create_an_announcement(): void
    {
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($parent)->postJson('/api/v1/announcements', [
            'title' => 'From home',
            'body' => 'Please ignore.',
            'audience' => 'whole_school',
        ])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->actingAs($parent)->postJson('/api/v1/announcements', [
            'title' => '',
            'body' => '',
        ])->assertForbidden();
    }

    public function test_validation_errors_use_the_envelope(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => '',
            'audience' => 'not-an-audience',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['title', 'body', 'audience']);
    }

    public function test_unknown_announcement_is_not_found(): void
    {
        $this->actingAs($this->admin())->getJson('/api/v1/announcements/999')
            ->assertNotFound()
            ->assertJsonPath('success', false);
    }

    public function test_admin_can_revise_archive_and_remove_a_circular(): void
    {
        $admin = $this->admin();

        $created = $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'Sports day',
            'body' => 'Friday on the field.',
            'audience' => 'whole_school',
            'status' => 'draft',
        ])->assertCreated()->json('data');

        $this->actingAs($admin)->putJson('/api/v1/announcements/'.$created['id'], [
            'title' => 'Sports day — confirmed',
            'status' => 'published',
        ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.title', 'Sports day — confirmed')
            ->assertJsonPath('data.status', 'published');

        $this->actingAs($admin)->putJson('/api/v1/announcements/'.$created['id'], [
            'status' => 'archived',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->actingAs($admin)->deleteJson('/api/v1/announcements/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($admin)->getJson('/api/v1/announcements/'.$created['id'])
            ->assertNotFound();
    }
}
