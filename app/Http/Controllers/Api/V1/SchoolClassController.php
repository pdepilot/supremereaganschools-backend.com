<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreSchoolClassRequest;
use App\Http\Requests\Academic\UpdateSchoolClassRequest;
use App\Http\Resources\Academic\SchoolClassResource;
use App\Models\SchoolClass;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SchoolClassController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', SchoolClass::class);

        $classes = SchoolClass::query()->with(['level', 'sections'])->orderBy('sort_order')->get();

        return ApiResponse::success('Classes retrieved.', SchoolClassResource::collection($classes)->resolve());
    }

    public function store(StoreSchoolClassRequest $request): JsonResponse
    {
        $class = SchoolClass::query()->create($request->validated());

        return ApiResponse::success('Class created.', (new SchoolClassResource($class->load('level')))->resolve(), 201);
    }

    public function show(SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('view', $schoolClass);

        return ApiResponse::success('Class retrieved.', (new SchoolClassResource($schoolClass->load(['level', 'sections'])))->resolve());
    }

    public function update(UpdateSchoolClassRequest $request, SchoolClass $schoolClass): JsonResponse
    {
        $schoolClass->update($request->validated());

        return ApiResponse::success('Class updated.', (new SchoolClassResource($schoolClass->fresh(['level', 'sections'])))->resolve());
    }

    public function destroy(SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('delete', $schoolClass);

        if ($schoolClass->sections()->exists()) {
            throw ValidationException::withMessages([
                'class' => 'This class cannot be deleted because arms/sections exist.',
            ]);
        }

        $schoolClass->delete();

        return ApiResponse::success('Class deleted.');
    }
}
