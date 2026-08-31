<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\PromoteAcademicSessionRequest;
use App\Http\Requests\Academic\StoreAcademicSessionRequest;
use App\Http\Requests\Academic\UpdateAcademicSessionRequest;
use App\Http\Resources\Academic\AcademicSessionResource;
use App\Models\AcademicSession;
use App\Services\AcademicSessionPromotionService;
use App\Services\AcademicSessionService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AcademicSessionController extends Controller
{
    public function __construct(
        private readonly AcademicSessionService $sessions,
        private readonly AcademicSessionPromotionService $promotions,
    ) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AcademicSession::class);

        $sessions = AcademicSession::query()->with('terms')->orderByDesc('starts_on')->get();

        return ApiResponse::success('Academic sessions retrieved.', AcademicSessionResource::collection($sessions)->resolve());
    }

    public function store(StoreAcademicSessionRequest $request): JsonResponse
    {
        $session = $this->sessions->create($request->validated(), $request->user()?->id);

        return ApiResponse::success('Academic session created.', (new AcademicSessionResource($session))->resolve(), 201);
    }

    public function show(AcademicSession $academicSession): JsonResponse
    {
        $this->authorize('view', $academicSession);

        return ApiResponse::success(
            'Academic session retrieved.',
            (new AcademicSessionResource($academicSession->load('terms')))->resolve(),
        );
    }

    public function update(UpdateAcademicSessionRequest $request, AcademicSession $academicSession): JsonResponse
    {
        $session = $this->sessions->update($academicSession, $request->validated());

        return ApiResponse::success('Academic session updated.', (new AcademicSessionResource($session))->resolve());
    }

    public function destroy(AcademicSession $academicSession): JsonResponse
    {
        $this->authorize('delete', $academicSession);
        $this->sessions->delete($academicSession);

        return ApiResponse::success('Academic session deleted.');
    }

    public function activate(AcademicSession $academicSession): JsonResponse
    {
        $this->authorize('update', $academicSession);
        $session = $this->sessions->activate($academicSession);

        return ApiResponse::success('Academic session activated.', (new AcademicSessionResource($session))->resolve());
    }

    public function promote(PromoteAcademicSessionRequest $request, AcademicSession $academicSession): JsonResponse
    {
        $source = AcademicSession::query()->findOrFail($request->integer('source_academic_session_id'));
        $stats = $this->promotions->promote(
            $academicSession,
            $source,
            $request->validated(),
            $request->user()?->id,
        );

        return ApiResponse::success(
            'Forms, subjects, and continuing pupils were copied into '.$academicSession->name.'.',
            $stats,
        );
    }
}
