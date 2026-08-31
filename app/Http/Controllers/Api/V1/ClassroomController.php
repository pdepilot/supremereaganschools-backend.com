<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Classroom\StoreAnnouncementRequest;
use App\Http\Requests\Classroom\StoreAssignmentRequest;
use App\Http\Requests\Classroom\StoreAssignmentSubmissionRequest;
use App\Http\Requests\Classroom\StoreConversationRequest;
use App\Http\Requests\Classroom\StoreLearningMaterialRequest;
use App\Http\Requests\Classroom\StoreMessageRequest;
use App\Http\Requests\Classroom\StoreTimetableSlotRequest;
use App\Http\Requests\Classroom\UpdateAnnouncementRequest;
use App\Http\Requests\Classroom\UpdateAssignmentRequest;
use App\Http\Requests\Classroom\UpdateTimetableSlotRequest;
use App\Http\Resources\Classroom\AnnouncementResource;
use App\Http\Resources\Classroom\AssignmentResource;
use App\Http\Resources\Classroom\ConversationResource;
use App\Http\Resources\Classroom\LearningMaterialResource;
use App\Http\Resources\Classroom\MessageResource;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Conversation;
use App\Models\LearningMaterial;
use App\Models\TimetableSlot;
use App\Services\AnnouncementService;
use App\Services\AssignmentService;
use App\Services\ClassroomService;
use App\Services\MaterialService;
use App\Services\MessagingService;
use App\Services\TimetableService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    public function __construct(
        private readonly ClassroomService $classroom,
        private readonly AnnouncementService $announcements,
        private readonly TimetableService $timetable,
        private readonly AssignmentService $assignments,
        private readonly MaterialService $materials,
        private readonly MessagingService $messages,
    ) {}

    public function context(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Announcement::class);

        return ApiResponse::success('Classroom context retrieved.', $this->classroom->context($request->user()));
    }

    public function announcements(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Announcement::class);

        $rows = $this->announcements->visibleTo($request->user())->get();

        return ApiResponse::success('Announcements retrieved.', AnnouncementResource::collection($rows)->resolve());
    }

    public function storeAnnouncement(StoreAnnouncementRequest $request): JsonResponse
    {
        $announcement = $this->announcements->create($request->validated(), $request->user());

        return ApiResponse::success('Announcement dispatched.', (new AnnouncementResource($announcement))->resolve(), 201);
    }

    public function showAnnouncement(Announcement $announcement): JsonResponse
    {
        $this->authorize('view', $announcement);

        return ApiResponse::success(
            'Announcement retrieved.',
            (new AnnouncementResource($announcement->load(['creator', 'department'])))->resolve(),
        );
    }

    public function updateAnnouncement(UpdateAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $updated = $this->announcements->update($announcement, $request->validated(), $request->user());

        return ApiResponse::success('Announcement updated.', (new AnnouncementResource($updated))->resolve());
    }

    public function destroyAnnouncement(Request $request, Announcement $announcement): JsonResponse
    {
        $this->authorize('delete', $announcement);
        $this->announcements->delete($announcement, $request->user());

        return ApiResponse::success('Announcement removed.');
    }

    public function timetable(Request $request): JsonResponse
    {
        $this->authorize('viewAny', TimetableSlot::class);

        $offeringId = $request->integer('class_section_offering_id');
        if (! $offeringId) {
            $first = collect($this->classroom->context($request->user())['offerings'])->first();
            $offeringId = (int) ($first['id'] ?? 0);
        }

        abort_unless($offeringId > 0, 404);

        return ApiResponse::success(
            'Timetable retrieved.',
            $this->timetable->grid(
                $offeringId,
                $request->user(),
                $request->integer('term_id') ?: null,
                $request->integer('subject_id') ?: null,
            ),
        );
    }

    public function storeTimetable(StoreTimetableSlotRequest $request): JsonResponse
    {
        $slot = $this->timetable->create($request->validated(), $request->user());

        return ApiResponse::success('Timetable slot created.', $this->timetable->payload($slot), 201);
    }

    public function updateTimetable(UpdateTimetableSlotRequest $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $slot = $this->timetable->update($timetableSlot, $request->validated(), $request->user());

        return ApiResponse::success('Timetable slot updated.', $this->timetable->payload($slot));
    }

    public function destroyTimetable(Request $request, TimetableSlot $timetableSlot): JsonResponse
    {
        $this->authorize('delete', $timetableSlot);
        $this->timetable->delete($timetableSlot, $request->user());

        return ApiResponse::success('Timetable slot removed.');
    }

    public function assignments(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Assignment::class);

        $rows = $this->assignments->visibleTo(
            $request->user(),
            $request->integer('class_section_offering_id') ?: null,
            $request->integer('student_profile_id') ?: null,
        )->get();

        return ApiResponse::success('Assignments retrieved.', AssignmentResource::collection($rows)->resolve());
    }

    public function storeAssignment(StoreAssignmentRequest $request): JsonResponse
    {
        $assignment = $this->assignments->create($request->validated(), $request->user());

        return ApiResponse::success('Assignment set.', (new AssignmentResource($assignment))->resolve(), 201);
    }

    public function showAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('view', $assignment);

        $decorated = $this->assignments->decorate(
            $assignment,
            $request->user(),
            $request->integer('student_profile_id') ?: null,
        );

        return ApiResponse::success(
            'Assignment retrieved.',
            (new AssignmentResource($decorated))->resolve(),
        );
    }

    public function storeSubmission(StoreAssignmentSubmissionRequest $request, Assignment $assignment): JsonResponse
    {
        $submission = $this->assignments->submit(
            $assignment,
            $request->user(),
            $request->validated(),
            $request->file('file'),
        );

        $decorated = $this->assignments->decorate($assignment, $request->user());
        $decorated->setRelation('submissions', collect([$submission]));

        return ApiResponse::success('Work handed in.', (new AssignmentResource($decorated))->resolve());
    }

    public function submissions(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('reviewSubmissions', $assignment);

        return ApiResponse::success(
            'Submissions retrieved.',
            $this->assignments->submissionsFor($assignment, $request->user())->all(),
        );
    }

    public function updateAssignment(UpdateAssignmentRequest $request, Assignment $assignment): JsonResponse
    {
        $updated = $this->assignments->update($assignment, $request->validated(), $request->user());

        return ApiResponse::success('Assignment updated.', (new AssignmentResource($updated))->resolve());
    }

    public function destroyAssignment(Request $request, Assignment $assignment): JsonResponse
    {
        $this->authorize('delete', $assignment);
        $this->assignments->delete($assignment, $request->user());

        return ApiResponse::success('Assignment removed.');
    }

    public function materials(Request $request): JsonResponse
    {
        $this->authorize('viewAny', LearningMaterial::class);

        $rows = $this->materials->visibleTo(
            $request->user(),
            $request->integer('class_section_offering_id') ?: null,
            $request->integer('student_profile_id') ?: null,
        )->get();

        return ApiResponse::success('Learning materials retrieved.', LearningMaterialResource::collection($rows)->resolve());
    }

    public function storeMaterial(StoreLearningMaterialRequest $request): JsonResponse
    {
        $material = $this->materials->create(
            $request->validated(),
            $request->file('file'),
            $request->user(),
        );

        return ApiResponse::success('Material uploaded.', (new LearningMaterialResource($material))->resolve(), 201);
    }

    public function destroyMaterial(Request $request, LearningMaterial $learningMaterial): JsonResponse
    {
        $this->authorize('delete', $learningMaterial);
        $this->materials->delete($learningMaterial, $request->user());

        return ApiResponse::success('Material removed.');
    }

    public function recipients(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $people = $this->messages->recipients($request->user())->map(fn ($user) => [
            'id' => $user->id,
            'name' => $user->name,
        ])->values()->all();

        return ApiResponse::success('Message recipients retrieved.', $people);
    }

    public function conversations(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Conversation::class);

        $rows = $this->messages->inbox($request->user())->map(function (Conversation $conversation) use ($request) {
            $conversation->unread_count = $this->messages->unreadCount($conversation, $request->user());

            return $conversation;
        });

        return ApiResponse::success('Conversations retrieved.', ConversationResource::collection($rows)->resolve());
    }

    public function storeConversation(StoreConversationRequest $request): JsonResponse
    {
        $conversation = $this->messages->start($request->validated(), $request->user());
        $conversation->unread_count = 0;

        return ApiResponse::success('Conversation started.', (new ConversationResource($conversation))->resolve(), 201);
    }

    public function showConversation(Request $request, Conversation $conversation): JsonResponse
    {
        $opened = $this->messages->open($conversation, $request->user());
        $opened->unread_count = 0;

        return ApiResponse::success('Conversation retrieved.', (new ConversationResource($opened))->resolve());
    }

    public function storeMessage(StoreMessageRequest $request, Conversation $conversation): JsonResponse
    {
        $message = $this->messages->reply($conversation, (string) $request->validated('body'), $request->user());

        return ApiResponse::success('Message sent.', (new MessageResource($message))->resolve(), 201);
    }

    public function notifications(Request $request): JsonResponse
    {
        $rows = $request->user()->notifications()->limit(50)->get()->map(fn ($notification) => [
            'id' => $notification->id,
            'title' => $notification->data['title'] ?? '',
            'body' => $notification->data['body'] ?? '',
            'kind' => $notification->data['kind'] ?? null,
            'related_id' => $notification->data['related_id'] ?? null,
            'read_at' => $notification->read_at?->toIso8601String(),
            'created_at' => $notification->created_at?->toIso8601String(),
        ])->values()->all();

        return ApiResponse::success('Notifications retrieved.', [
            'unread_count' => $request->user()->unreadNotifications()->count(),
            'items' => $rows,
        ]);
    }

    public function readNotification(Request $request, string $notification): JsonResponse
    {
        $row = $request->user()->notifications()->findOrFail($notification);
        $row->markAsRead();

        return ApiResponse::success('Notification marked read.');
    }

    public function readAllNotifications(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return ApiResponse::success('Notifications marked read.');
    }
}
