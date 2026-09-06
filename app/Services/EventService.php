<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EventService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): Event
    {
        return DB::transaction(function () use ($payload) {
            $event = new Event($this->attributes($payload));
            $this->applyStatus($event, $payload);
            $event->save();

            if (! empty($payload['is_featured'])) {
                $this->clearOtherFeatured($event);
            }

            return $event->fresh() ?? $event;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(Event $event, array $payload): Event
    {
        return DB::transaction(function () use ($event, $payload) {
            $payload['id'] = $event->id;
            $event->fill($this->attributes($payload));
            $this->applyStatus($event, $payload);
            $event->save();

            if (! empty($payload['is_featured'])) {
                $this->clearOtherFeatured($event);
            }

            return $event->fresh() ?? $event;
        });
    }

    public function storeCoverImage(Event $event, UploadedFile $file, ?string $alt = null): Event
    {
        $this->deleteCoverFile($event);

        $directory = 'events/'.$event->id;
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME) ?: 'event')
            .'-'.Str::random(6).'.'.$file->guessExtension();
        $path = $file->storeAs($directory, $name, 'public');

        $event->cover_image = '/storage/'.$path;
        $event->cover_image_alt = $alt ?: $event->title;
        $event->save();

        return $event->fresh() ?? $event;
    }

    public function delete(Event $event): void
    {
        $this->deleteCoverFile($event);
        $event->delete();
    }

    private function deleteCoverFile(Event $event): void
    {
        if (blank($event->cover_image) || ! str_starts_with((string) $event->cover_image, '/storage/')) {
            return;
        }

        $relative = ltrim(substr((string) $event->cover_image, strlen('/storage/')), '/');
        if ($relative !== '') {
            Storage::disk('public')->delete($relative);
        }
    }

    private function clearOtherFeatured(Event $event): void
    {
        Event::query()
            ->where('id', '!=', $event->id)
            ->where('is_featured', true)
            ->update(['is_featured' => false]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function attributes(array $payload): array
    {
        $title = trim((string) ($payload['title'] ?? ''));
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($slug === '') {
            $slug = Str::slug($title) ?: 'event';
        }

        $base = $slug;
        $i = 1;
        while (
            Event::withTrashed()
                ->where('slug', $slug)
                ->when(isset($payload['id']), fn ($q) => $q->where('id', '!=', $payload['id']))
                ->exists()
        ) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return [
            'title' => $title,
            'slug' => $slug,
            'summary' => trim((string) ($payload['summary'] ?? '')),
            'body' => isset($payload['body']) ? trim((string) $payload['body']) : null,
            'category' => filled($payload['category'] ?? null) ? trim((string) $payload['category']) : null,
            'starts_at' => $payload['starts_at'],
            'ends_at' => $payload['ends_at'] ?? null,
            'location' => filled($payload['location'] ?? null) ? trim((string) $payload['location']) : null,
            'cover_image_alt' => filled($payload['cover_image_alt'] ?? null)
                ? trim((string) $payload['cover_image_alt'])
                : $title,
            'is_featured' => (bool) ($payload['is_featured'] ?? false),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function applyStatus(Event $event, array $payload): void
    {
        $status = EventStatus::tryFrom((string) ($payload['status'] ?? $event->status?->value ?? 'draft'))
            ?? EventStatus::Draft;

        $event->status = $status;

        if ($status === EventStatus::Published) {
            $event->published_at = $payload['published_at'] ?? $event->published_at ?? now();
        } else {
            $event->published_at = null;
        }
    }
}
