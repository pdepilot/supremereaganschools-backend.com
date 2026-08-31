<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreSubjectRequest;
use App\Http\Requests\Academic\UpdateSubjectRequest;
use App\Http\Resources\Academic\SubjectResource;
use App\Models\Subject;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class SubjectController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Subject::class);

        $subjects = Subject::query()->with('department')->orderBy('name')->get();

        return ApiResponse::success('Subjects retrieved.', SubjectResource::collection($subjects)->resolve());
    }

    public function store(StoreSubjectRequest $request): JsonResponse
    {
        $subject = Subject::query()->create($request->validated());

        return ApiResponse::success('Subject created.', (new SubjectResource($subject->load('department')))->resolve(), 201);
    }

    public function update(UpdateSubjectRequest $request, Subject $subject): JsonResponse
    {
        $subject->update($request->validated());

        return ApiResponse::success('Subject updated.', (new SubjectResource($subject->fresh('department')))->resolve());
    }

    public function destroy(Subject $subject): JsonResponse
    {
        $this->authorize('delete', $subject);

        if ($subject->subjectOfferings()->exists()) {
            throw ValidationException::withMessages([
                'subject' => 'This subject cannot be deleted because it is offered to classes.',
            ]);
        }

        $subject->delete();

        return ApiResponse::success('Subject deleted.');
    }
}
