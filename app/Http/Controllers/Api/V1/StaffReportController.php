<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuthPortal;
use App\Http\Controllers\Controller;
use App\Http\Requests\Portal\StaffReportRequest;
use App\Services\StaffReportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffReportController extends Controller
{
    public function __construct(private readonly StaffReportService $reports) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertStaff($request);

        return ApiResponse::success(
            'Faculty reports retrieved.',
            $this->reports->catalogue($request->user()),
        );
    }

    public function show(StaffReportRequest $request): JsonResponse
    {
        $this->assertStaff($request);

        return ApiResponse::success(
            'Faculty report generated.',
            $this->reports->generate($request->user(), $request->validated()),
        );
    }

    public function export(StaffReportRequest $request): StreamedResponse
    {
        $this->assertStaff($request);

        $file = $this->reports->export($request->user(), $request->validated());
        $filename = $file['filename'];

        return response()->streamDownload(function () use ($file): void {
            echo $file['csv'];
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function assertStaff(Request $request): void
    {
        $user = $request->user();
        abort_unless(
            $user !== null && $user->hasAnyRole(...AuthPortal::Staff->allowedRoles()),
            403,
        );
    }
}
