<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\PortalReportRequest;
use App\Models\SchoolSetting;
use App\Services\PortalReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalReportController extends Controller
{
    public function __construct(private readonly PortalReportService $reports) {}

    public function show(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SchoolSetting::class);

        return $this->live(
            ApiResponse::success(
                'School reports retrieved.',
                $this->reports->assay($request->user()),
            ),
        );
    }

    public function catalogue(): JsonResponse
    {
        $this->authorize('viewAny', SchoolSetting::class);

        return $this->live(
            ApiResponse::success('Report catalogue retrieved.', $this->reports->catalogue()),
        );
    }

    public function generate(PortalReportRequest $request): JsonResponse
    {
        return $this->live(
            ApiResponse::success(
                'School report generated.',
                $this->reports->generate($request->user(), $request->validated()),
            ),
        );
    }

    public function export(PortalReportRequest $request): StreamedResponse
    {
        $file = $this->reports->export($request->user(), $request->validated());
        $filename = $file['filename'];

        return response()->streamDownload(function () use ($file): void {
            echo $file['csv'];
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function live(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
