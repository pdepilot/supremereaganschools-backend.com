<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreLevelRequest;
use App\Http\Requests\Academic\UpdateLevelRequest;
use App\Http\Resources\Academic\LevelResource;
use App\Models\Level;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class LevelController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Level::class);

        $levels = Level::query()->with('schoolClasses.sections')->orderBy('sort_order')->get();

        return ApiResponse::success('Levels retrieved.', LevelResource::collection($levels)->resolve());
    }

    public function store(StoreLevelRequest $request): JsonResponse
    {
        $level = Level::query()->create($request->validated());

        return ApiResponse::success('Level created.', (new LevelResource($level))->resolve(), 201);
    }

    public function update(UpdateLevelRequest $request, Level $level): JsonResponse
    {
        $level->update($request->validated());

        return ApiResponse::success('Level updated.', (new LevelResource($level->fresh()))->resolve());
    }

    public function destroy(Level $level): JsonResponse
    {
        $this->authorize('delete', $level);

        if ($level->schoolClasses()->exists()) {
            throw ValidationException::withMessages([
                'level' => 'This level cannot be deleted because classes exist.',
            ]);
        }

        $level->delete();

        return ApiResponse::success('Level deleted.');
    }
}
