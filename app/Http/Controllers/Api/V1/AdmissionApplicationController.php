<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admissions\StoreAdmissionApplicationRequest;
use App\Http\Requests\Admissions\UpdateAdmissionApplicationRequest;
use App\Http\Resources\Admissions\AdmissionApplicationResource;
use App\Models\AdmissionApplication;
use App\Services\ApplicationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdmissionApplicationController extends Controller
{
    public function __construct(private readonly ApplicationService $applications) {}

    public function index(): JsonResponse
    {
        $this->authorize('viewAny', AdmissionApplication::class);

        $rows = AdmissionApplication::query()
            ->with(['documents', 'level', 'academicSession'])
            ->orderByDesc('created_at')
            ->get();

        return ApiResponse::success(
            'Applications retrieved.',
            AdmissionApplicationResource::collection($rows)->resolve(),
        );
    }

    public function store(StoreAdmissionApplicationRequest $request): JsonResponse
    {
        $application = $this->applications->submit($request->safe()->except([
            'passport_photo',
            'passportPhoto',
            'birth_certificate',
            'birthCert',
            'exam_receipt',
            'examReceipt',
        ]), $request->attachments());

        return ApiResponse::success(
            'Application received.',
            (new AdmissionApplicationResource($application))->resolve(),
            201,
        );
    }

    public function show(AdmissionApplication $admissionApplication): JsonResponse
    {
        $this->authorize('view', $admissionApplication);

        return ApiResponse::success(
            'Application retrieved.',
            (new AdmissionApplicationResource($admissionApplication->load(['documents', 'level', 'academicSession'])))->resolve(),
        );
    }

    public function update(UpdateAdmissionApplicationRequest $request, AdmissionApplication $admissionApplication): JsonResponse
    {
        $application = $this->applications->update($admissionApplication, $request->validated(), $request->user());

        return ApiResponse::success('Application updated.', (new AdmissionApplicationResource($application))->resolve());
    }
}
