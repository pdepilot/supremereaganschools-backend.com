<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fees\StoreFeeTypeRequest;
use App\Http\Requests\Fees\UpdateFeeTypeRequest;
use App\Http\Resources\Fees\FeeTypeResource;
use App\Models\FeeType;
use App\Services\FeeCatalogueService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class FeeTypeController extends Controller
{
    public function __construct(private readonly FeeCatalogueService $catalogue) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', FeeType::class);

        $types = FeeType::query()->orderBy('name')->get();

        return ApiResponse::success('Fee types retrieved.', FeeTypeResource::collection($types)->resolve());
    }

    public function store(StoreFeeTypeRequest $request): JsonResponse
    {
        $type = $this->catalogue->createType($request->validated());

        return ApiResponse::success('Fee type created.', (new FeeTypeResource($type))->resolve(), 201);
    }

    public function show(FeeType $feeType): JsonResponse
    {
        $this->authorize('view', $feeType);

        return ApiResponse::success('Fee type retrieved.', (new FeeTypeResource($feeType))->resolve());
    }

    public function update(UpdateFeeTypeRequest $request, FeeType $feeType): JsonResponse
    {
        $type = $this->catalogue->updateType($feeType, $request->validated());

        return ApiResponse::success('Fee type updated.', (new FeeTypeResource($type))->resolve());
    }

    public function destroy(FeeType $feeType): JsonResponse
    {
        $this->authorize('delete', $feeType);
        $this->catalogue->deactivateType($feeType);

        return ApiResponse::success('Fee type deactivated.', (new FeeTypeResource($feeType->fresh()))->resolve());
    }
}
