<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreSubjectOfferingRequest;
use App\Http\Resources\Academic\SubjectOfferingResource;
use App\Models\SubjectOffering;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubjectOfferingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SubjectOffering::class);

        $offerings = SubjectOffering::query()
            ->with(['subject', 'classSectionOffering.classSection', 'classSectionOffering.academicSession'])
            ->when($request->integer('class_section_offering_id'), fn ($query, $id) => $query->where('class_section_offering_id', $id))
            ->when($request->integer('subject_id'), fn ($query, $id) => $query->where('subject_id', $id))
            ->get();

        return ApiResponse::success('Subject offerings retrieved.', SubjectOfferingResource::collection($offerings)->resolve());
    }

    public function store(StoreSubjectOfferingRequest $request): JsonResponse
    {
        $offering = SubjectOffering::query()->create($request->validated());

        return ApiResponse::success(
            'Subject offering created.',
            (new SubjectOfferingResource($offering->load(['subject', 'classSectionOffering.classSection'])))->resolve(),
            201,
        );
    }

    public function destroy(SubjectOffering $subjectOffering): JsonResponse
    {
        $this->authorize('delete', $subjectOffering);
        $subjectOffering->delete();

        return ApiResponse::success('Subject offering removed.');
    }
}
