<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Academic\StoreDepartmentRequest;
use App\Http\Resources\Academic\DepartmentResource;
use App\Models\Department;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Department::class);

        return ApiResponse::success(
            'Departments retrieved.',
            DepartmentResource::collection(Department::query()->orderBy('name')->get())->resolve(),
        );
    }

    public function store(StoreDepartmentRequest $request): JsonResponse
    {
        $department = Department::query()->create($request->validated())->refresh();

        return ApiResponse::success(
            'Department created.',
            (new DepartmentResource($department))->resolve(),
            201,
        );
    }
}
