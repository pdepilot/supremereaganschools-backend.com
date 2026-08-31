<?php

namespace Tests\Feature\Classroom;

use App\Enums\RoleSlug;
use App\Models\Conversation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAcademicContext;
use Tests\TestCase;

class MessagingApiTest extends TestCase
{
    use CreatesAcademicContext;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleSeeder::class);
    }

    public function test_unauthenticated_inbox_is_rejected(): void
    {
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
        $this->getJson('/api/v1/notifications')->assertUnauthorized();
    }

    public function test_teacher_can_message_linked_parent_but_not_an_unrelated_parent(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacher), $offering);

        $linkedParent = $this->userWithRole(RoleSlug::Parent, ['name' => 'Mrs. Okafor']);
        $child = $this->student();
        $this->linkGuardian($this->guardian($linkedParent), $child);
        $this->enroll($child, $offering);

        $otherParent = $this->userWithRole(RoleSlug::Parent, ['name' => 'Mr. Nwosu']);

        $this->actingAs($teacher)->postJson('/api/v1/conversations', [
            'recipient_id' => $otherParent->id,
            'subject' => 'Hello',
            'body' => 'This should not send.',
        ])->assertForbidden();

        $this->actingAs($teacher)->postJson('/api/v1/conversations', [
            'recipient_id' => $linkedParent->id,
            'subject' => 'Mathematics homework',
            'body' => 'Please remind Chiamaka.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.subject', 'Mathematics homework');

        $this->actingAs($linkedParent)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->assertJsonFragment(['title' => 'Mathematics homework']);
    }

    public function test_participant_can_reply_and_outsider_cannot(): void
    {
        $offering = $this->offering();
        $teacher = $this->userWithRole(RoleSlug::Teacher);
        $this->classTeacher($this->staff($teacher), $offering);

        $parent = $this->userWithRole(RoleSlug::Parent);
        $child = $this->student();
        $this->linkGuardian($this->guardian($parent), $child);
        $this->enroll($child, $offering);

        $outsider = $this->userWithRole(RoleSlug::Teacher);
        $this->staff($outsider);

        $conversationId = $this->actingAs($teacher)->postJson('/api/v1/conversations', [
            'recipient_id' => $parent->id,
            'subject' => 'Class note',
            'body' => 'First line.',
        ])->assertCreated()->json('data.id');

        $this->actingAs($parent)->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'body' => 'Thank you.',
        ])
            ->assertCreated()
            ->assertJsonPath('data.body', 'Thank you.');

        $this->actingAs($outsider)->getJson('/api/v1/conversations/'.$conversationId)
            ->assertForbidden();

        $this->actingAs($outsider)->postJson('/api/v1/conversations/'.$conversationId.'/messages', [
            'body' => 'I should not be here.',
        ])->assertForbidden();
    }

    public function test_marking_a_notification_read_clears_it(): void
    {
        $admin = $this->admin();
        $parent = $this->userWithRole(RoleSlug::Parent);

        $this->actingAs($admin)->postJson('/api/v1/announcements', [
            'title' => 'Fees reminder',
            'body' => 'Balances close on Friday.',
            'audience' => 'parents',
        ])->assertCreated();

        $id = $this->actingAs($parent)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 1)
            ->json('data.items.0.id');

        $this->actingAs($parent)->postJson('/api/v1/notifications/'.$id.'/read')
            ->assertOk();

        $this->actingAs($parent)->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonPath('data.unread_count', 0);
    }

    public function test_missing_conversation_is_not_found(): void
    {
        $this->actingAs($this->admin())->getJson('/api/v1/conversations/999')
            ->assertNotFound();
    }
}
