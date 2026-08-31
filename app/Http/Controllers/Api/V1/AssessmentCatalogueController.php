<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Assessments\AssessmentTypeResource;
use App\Http\Resources\Assessments\GradeScaleResource;
use App\Models\AssessmentScore;
use App\Models\AssessmentType;
use App\Models\GradeScale;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class AssessmentCatalogueController extends Controller
{
    public function types(): JsonResponse
    {
        $this->authorize('viewAny', AssessmentScore::class);

        $types = AssessmentType::query()->where('is_active', true)->orderBy('sort_order')->get();

        return ApiResponse::success('Assessment types retrieved.', AssessmentTypeResource::collection($types)->resolve());
    }

    public function scales(): JsonResponse
    {
        $this->authorize('viewAny', AssessmentScore::class);

        $scales = GradeScale::query()->orderBy('sort_order')->get();

        return ApiResponse::success('Grade scales retrieved.', GradeScaleResource::collection($scales)->resolve());
    }
}
