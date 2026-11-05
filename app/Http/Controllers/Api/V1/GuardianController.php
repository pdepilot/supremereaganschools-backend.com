<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\StoreGuardianRequest;
use App\Http\Requests\People\StoreGuardianStudentRequest;
use App\Http\Requests\People\UpdateGuardianRequest;
use App\Http\Resources\People\GuardianResource;
use App\Http\Resources\People\GuardianStudentResource;
use App\Models\GuardianProfile;
use App\Models\GuardianStudent;
use App\Services\GuardianService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardianController extends Controller
{
    public function __construct(private readonly GuardianService $guardians) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', GuardianProfile::class);

        $query = GuardianProfile::query()->with(['user', 'students'])->orderBy('full_name');

        if ($request->filled('search')) {
            $search = '%' . trim((string) $request->input('search')) . '%';
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', $search)
                    ->orWhere('phone', 'like', $search)
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', $search));
            });
        }

        if ($request->has('page') || $request->has('per_page')) {
            $perPage = max(1, min(100, $request->integer('per_page', 10)));
            $paginator = $query->paginate($perPage);

            return ApiResponse::paginated(
                'Guardians retrieved.',
                GuardianResource::collection($paginator->items())->resolve(),
                $paginator
            );
        }

        $guardians = $query->get();

        return ApiResponse::success('Guardians retrieved.', GuardianResource::collection($guardians)->resolve());
    }

    public function store(StoreGuardianRequest $request): JsonResponse
    {
        $guardian = $this->guardians->create($request->validated());

        return ApiResponse::success('Guardian created.', (new GuardianResource($guardian))->resolve(), 201);
    }

    public function show(GuardianProfile $guardianProfile): JsonResponse
    {
        $this->authorize('view', $guardianProfile);

        return ApiResponse::success(
            'Guardian retrieved.',
            (new GuardianResource($guardianProfile->load(['user', 'students.enrollments.classSectionOffering.classSection'])))->resolve(),
        );
    }

    public function update(UpdateGuardianRequest $request, GuardianProfile $guardianProfile): JsonResponse
    {
        $guardian = $this->guardians->update($guardianProfile, $request->validated());

        return ApiResponse::success('Guardian updated.', (new GuardianResource($guardian))->resolve());
    }

    public function destroy(GuardianProfile $guardianProfile): JsonResponse
    {
        $this->authorize('delete', $guardianProfile);
        $this->guardians->delete($guardianProfile);

        return ApiResponse::success('Guardian removed.');
    }

    public function link(StoreGuardianStudentRequest $request, GuardianProfile $guardianProfile): JsonResponse
    {
        $link = $this->guardians->link($guardianProfile, $request->validated());

        return ApiResponse::success('Guardian linked to pupil.', (new GuardianStudentResource($link))->resolve(), 201);
    }

    public function unlink(GuardianStudent $guardianStudent): JsonResponse
    {
        $this->authorize('delete', $guardianStudent);
        $this->guardians->unlink($guardianStudent);

        return ApiResponse::success('Guardian unlinked from pupil.');
    }
}
