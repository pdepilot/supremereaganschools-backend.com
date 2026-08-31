<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SchoolWing;
use App\Http\Controllers\Controller;
use App\Models\SchoolSetting;
use App\Services\LevelDeskService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LevelDeskController extends Controller
{
    public function __construct(private readonly LevelDeskService $desks) {}

    public function show(string $wing): JsonResponse
    {
        $this->authorize('viewAny', SchoolSetting::class);

        $resolved = SchoolWing::tryFrom($wing);
        abort_unless($resolved instanceof SchoolWing, 404);

        return ApiResponse::success(
            'Level desk retrieved.',
            $this->desks->snapshot($resolved),
        );
    }
}
