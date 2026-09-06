<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EventStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEventRequest;
use App\Http\Requests\UpdateEventRequest;
use App\Http\Resources\EventResource;
use App\Models\Event;
use App\Services\EventService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function __construct(private readonly EventService $events) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Event::class);

        $rows = Event::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = '%'.$request->string('q').'%';
                $q->where(fn ($inner) => $inner
                    ->where('title', 'like', $term)
                    ->orWhere('summary', 'like', $term)
                    ->orWhere('category', 'like', $term)
                    ->orWhere('location', 'like', $term));
            })
            ->orderByDesc('is_featured')
            ->orderBy('starts_at')
            ->paginate(12);

        return ApiResponse::success('Events retrieved.', [
            'items' => EventResource::collection($rows)->resolve(),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'from' => $rows->firstItem(),
                'to' => $rows->lastItem(),
                'total' => $rows->total(),
            ],
            'summary' => $this->summary(),
        ]);
    }

    public function store(StoreEventRequest $request): JsonResponse
    {
        $event = $this->events->create($request->validated());

        if ($request->hasFile('cover_image')) {
            $event = $this->events->storeCoverImage($event, $request->file('cover_image'), $request->input('cover_image_alt'));
        }

        return ApiResponse::success('Event saved.', (new EventResource($event))->resolve(), 201);
    }

    public function show(Event $event): JsonResponse
    {
        $this->authorize('view', $event);

        return ApiResponse::success('Event retrieved.', (new EventResource($event))->resolve());
    }

    public function update(UpdateEventRequest $request, Event $event): JsonResponse
    {
        $updated = $this->events->update($event, $request->validated());

        if ($request->hasFile('cover_image')) {
            $updated = $this->events->storeCoverImage($updated, $request->file('cover_image'), $request->input('cover_image_alt'));
        }

        return ApiResponse::success('Event updated.', (new EventResource($updated))->resolve());
    }

    public function destroy(Event $event): JsonResponse
    {
        $this->authorize('delete', $event);
        $this->events->delete($event);

        return ApiResponse::success('Event deleted.');
    }

    /**
     * @return array<string, int>
     */
    private function summary(): array
    {
        return [
            'total' => Event::query()->count(),
            'published' => Event::query()->where('status', EventStatus::Published)->count(),
            'drafts' => Event::query()->where('status', EventStatus::Draft)->count(),
            'featured' => Event::query()->where('is_featured', true)->count(),
            'upcoming' => Event::query()->published()->upcoming()->count(),
        ];
    }
}
