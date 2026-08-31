<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreTermRequest;
use App\Http\Requests\Academic\UpdateTermRequest;
use App\Http\Resources\Academic\TermResource;
use App\Models\AcademicSession;
use App\Models\Term;
use App\Services\TermService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class TermController extends Controller
{
    public function __construct(private readonly TermService $terms) {}

    public function index(AcademicSession $academicSession): JsonResponse
    {
        $this->authorize('viewAny', Term::class);

        return ApiResponse::success(
            'Terms retrieved.',
            TermResource::collection($academicSession->terms()->orderBy('term_number')->get())->resolve(),
        );
    }

    public function store(StoreTermRequest $request, AcademicSession $academicSession): JsonResponse
    {
        $term = $this->terms->create($academicSession, $request->validated());

        return ApiResponse::success('Term created.', (new TermResource($term))->resolve(), 201);
    }

    public function update(UpdateTermRequest $request, Term $term): JsonResponse
    {
        $term = $this->terms->update($term, $request->validated());

        return ApiResponse::success('Term updated.', (new TermResource($term))->resolve());
    }

    public function activate(Term $term): JsonResponse
    {
        $this->authorize('update', $term);

        return ApiResponse::success(
            'Term sealed.',
            (new TermResource($this->terms->activate($term)))->resolve(),
        );
    }

    public function destroy(Term $term): JsonResponse
    {
        $this->authorize('delete', $term);
        $this->terms->delete($term);

        return ApiResponse::success('Term deleted.');
    }
}
