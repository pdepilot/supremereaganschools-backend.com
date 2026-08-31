<?php

namespace App\Services;

use App\Enums\AnnouncementAudience;
use App\Enums\AnnouncementStatus;
use App\Enums\RoleSlug;
use App\Models\Announcement;
use App\Models\User;
use App\Notifications\SchoolNotice;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

class AnnouncementService
{
    public function __construct(private readonly PeopleAccessService $access) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, User $actor): Announcement
    {
        $this->assertMayWrite($actor);

        $status = $attributes['status'] instanceof AnnouncementStatus
            ? $attributes['status']
            : AnnouncementStatus::from($attributes['status'] ?? AnnouncementStatus::Published->value);
        $audience = $attributes['audience'] instanceof AnnouncementAudience
            ? $attributes['audience']
            : AnnouncementAudience::from($attributes['audience']);

        $departmentId = $attributes['department_id'] ?? null;
        if ($audience === AnnouncementAudience::Department && $departmentId === null) {
            $departmentId = $actor->staffProfile?->department_id;
        }

        $announcement = Announcement::query()->create([
            'title' => $attributes['title'],
            'body' => $attributes['body'],
            'category' => $attributes['category'] ?? null,
            'audience' => $audience,
            'department_id' => $departmentId,
            'status' => $status,
            'published_at' => $status === AnnouncementStatus::Published ? now() : null,
            'created_by' => $actor->id,
        ]);

        if ($status === AnnouncementStatus::Published) {
            $this->notifyAudience($announcement);
        }

        return $announcement->load(['creator', 'department']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Announcement $announcement, array $attributes, User $actor): Announcement
    {
        $this->assertMayManage($actor, $announcement);

        $wasPublished = $announcement->status === AnnouncementStatus::Published;
        $status = isset($attributes['status'])
            ? AnnouncementStatus::from($attributes['status'])
            : $announcement->status;

        $announcement->fill([
            'title' => $attributes['title'] ?? $announcement->title,
            'body' => $attributes['body'] ?? $announcement->body,
            'category' => array_key_exists('category', $attributes) ? $attributes['category'] : $announcement->category,
            'audience' => $attributes['audience'] ?? $announcement->audience,
            'department_id' => array_key_exists('department_id', $attributes) ? $attributes['department_id'] : $announcement->department_id,
            'status' => $status,
            'published_at' => $status === AnnouncementStatus::Published
                ? ($announcement->published_at ?? now())
                : $announcement->published_at,
        ])->save();

        if ($status === AnnouncementStatus::Published && ! $wasPublished) {
            $this->notifyAudience($announcement);
        }

        return $announcement->fresh(['creator', 'department']);
    }

    public function delete(Announcement $announcement, User $actor): void
    {
        $this->assertMayManage($actor, $announcement);
        $announcement->delete();
    }

    public function visibleTo(User $user)
    {
        $query = Announcement::query()->with(['creator', 'department']);

        if ($this->access->administers($user)) {
            return $query->orderByDesc('published_at')->orderByDesc('id');
        }

        $audiences = [AnnouncementAudience::WholeSchool->value];

        if ($this->access->isParent($user)) {
            $audiences[] = AnnouncementAudience::Parents->value;
        }
        if ($this->access->isTeacher($user)) {
            $audiences[] = AnnouncementAudience::Staff->value;
            $audiences[] = AnnouncementAudience::TeachingStaff->value;
        }
        if ($this->access->isStudent($user)) {
            $audiences[] = AnnouncementAudience::Students->value;
        }
        if ($this->access->isInSecondary($user)) {
            $audiences[] = AnnouncementAudience::Secondary->value;
        }
        if ($user->hasRole(RoleSlug::Staff) && ! $user->hasRole(RoleSlug::Teacher)) {
            $audiences[] = AnnouncementAudience::NonTeachingStaff->value;
            $audiences[] = AnnouncementAudience::Staff->value;
        }

        $departmentId = $user->staffProfile?->department_id;

        return $query->where(function ($outer) use ($user, $audiences, $departmentId) {
            $outer->where(function ($published) use ($audiences, $departmentId) {
                $published->where('status', AnnouncementStatus::Published)
                    ->where(function ($inner) use ($audiences, $departmentId) {
                        $inner->whereIn('audience', $audiences);
                        if ($departmentId) {
                            $inner->orWhere(function ($dept) use ($departmentId) {
                                $dept->where('audience', AnnouncementAudience::Department->value)
                                    ->where('department_id', $departmentId);
                            });
                        }
                    });
            })->orWhere('created_by', $user->id);
        })->orderByDesc('published_at')->orderByDesc('id');
    }

    public function canView(User $user, Announcement $announcement): bool
    {
        if ($this->access->administers($user) || (int) $announcement->created_by === (int) $user->id) {
            return true;
        }

        if ($announcement->status !== AnnouncementStatus::Published) {
            return false;
        }

        return $this->visibleTo($user)->whereKey($announcement->id)->exists();
    }

    private function notifyAudience(Announcement $announcement): void
    {
        $this->recipients($announcement)->each(function (User $user) use ($announcement) {
            $user->notify(new SchoolNotice(
                $announcement->title,
                $announcement->body,
                'announcement',
                $announcement->id,
            ));
        });
    }

    /**
     * @return Collection<int, User>
     */
    private function recipients(Announcement $announcement): Collection
    {
        $query = User::query()->where('status', \App\Enums\UserStatus::Active);

        return match ($announcement->audience) {
            AnnouncementAudience::Parents => $query->whereHas('roles', fn ($role) => $role->where('slug', RoleSlug::Parent))->get(),
            AnnouncementAudience::Staff, AnnouncementAudience::TeachingStaff => $query->whereHas('roles', fn ($role) => $role->whereIn('slug', [
                RoleSlug::Teacher->value,
                RoleSlug::Staff->value,
                RoleSlug::Principal->value,
                RoleSlug::VicePrincipal->value,
            ]))->get(),
            AnnouncementAudience::NonTeachingStaff => $query->whereHas('roles', fn ($role) => $role->where('slug', RoleSlug::Staff))->get(),
            AnnouncementAudience::Students => $query->whereHas('roles', fn ($role) => $role->where('slug', RoleSlug::Student))->get(),
            AnnouncementAudience::Department => $query->whereHas('staffProfile', fn ($staff) => $staff->where('department_id', $announcement->department_id))->get(),
            default => $query->get(),
        };
    }

    private function assertMayWrite(User $actor): void
    {
        if (! $this->access->administers($actor) && ! $this->access->isTeacher($actor)) {
            throw new AuthorizationException;
        }
    }

    private function assertMayManage(User $actor, Announcement $announcement): void
    {
        if ($this->access->administers($actor)) {
            return;
        }

        if ($this->access->isTeacher($actor) && (int) $announcement->created_by === (int) $actor->id) {
            return;
        }

        throw new AuthorizationException;
    }
}
