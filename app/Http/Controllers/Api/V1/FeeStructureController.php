<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fees\StoreFeeStructureRequest;
use App\Http\Requests\Fees\UpdateFeeStructureRequest;
use App\Http\Resources\Fees\FeeStructureResource;
use App\Models\FeeStructure;
use App\Services\FeeCatalogueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeeStructureController extends Controller
{
    public function __construct(private readonly FeeCatalogueService $catalogue) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', FeeStructure::class);

        $query = FeeStructure::query()->with(['feeType', 'academicSession', 'term', 'level', 'schoolClass']);

        if ($request->filled('academic_session_id')) {
            $query->where('academic_session_id', $request->integer('academic_session_id'));
        }

        if ($request->filled('term_id')) {
            $query->where('term_id', $request->integer('term_id'));
        }

        $rows = $query->orderBy('id')->get();

        return ApiResponse::success('Fee structures retrieved.', FeeStructureResource::collection($rows)->resolve());
    }

    public function store(StoreFeeStructureRequest $request): JsonResponse
    {
        $structure = $this->catalogue->createStructure($request->validated());

        return ApiResponse::success('Fee structure created.', (new FeeStructureResource($structure))->resolve(), 201);
    }

    public function show(FeeStructure $feeStructure): JsonResponse
    {
        $this->authorize('view', $feeStructure);

        return ApiResponse::success(
            'Fee structure retrieved.',
            (new FeeStructureResource($feeStructure->load(['feeType', 'academicSession', 'term', 'level', 'schoolClass'])))->resolve(),
        );
    }

    public function update(UpdateFeeStructureRequest $request, FeeStructure $feeStructure): JsonResponse
    {
        $structure = $this->catalogue->updateStructure($feeStructure, $request->validated());

        return ApiResponse::success('Fee structure updated.', (new FeeStructureResource($structure))->resolve());
    }

    public function destroy(FeeStructure $feeStructure): JsonResponse
    {
        $this->authorize('delete', $feeStructure);
        $this->catalogue->deleteStructure($feeStructure);

        return ApiResponse::success('Fee structure removed.');
    }
}
