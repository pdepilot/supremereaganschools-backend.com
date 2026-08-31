<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreClassSectionRequest;
use App\Http\Requests\Academic\UpdateClassSectionRequest;
use App\Http\Resources\Academic\ClassSectionResource;
use App\Models\ClassSection;
use App\Models\SchoolClass;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ClassSectionController extends Controller
{
    public function index(SchoolClass $schoolClass): JsonResponse
    {
        $this->authorize('viewAny', ClassSection::class);

        return ApiResponse::success(
            'Arms retrieved.',
            ClassSectionResource::collection($schoolClass->sections()->orderBy('arm')->get())->resolve(),
        );
    }

    public function store(StoreClassSectionRequest $request, SchoolClass $schoolClass): JsonResponse
    {
        $section = $schoolClass->sections()->create($request->validated());

        return ApiResponse::success('Arm created.', (new ClassSectionResource($section->load('schoolClass')))->resolve(), 201);
    }

    public function update(UpdateClassSectionRequest $request, ClassSection $classSection): JsonResponse
    {
        $classSection->update($request->validated());

        return ApiResponse::success('Arm updated.', (new ClassSectionResource($classSection->fresh('schoolClass')))->resolve());
    }

    public function destroy(ClassSection $classSection): JsonResponse
    {
        $this->authorize('delete', $classSection);

        if ($classSection->offerings()->exists()) {
            throw ValidationException::withMessages([
                'section' => 'This arm cannot be deleted because session offerings exist.',
            ]);
        }

        $classSection->delete();

        return ApiResponse::success('Arm deleted.');
    }
}
