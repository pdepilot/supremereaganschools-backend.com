<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PermissionSlug;
use App\Enums\StaffAttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\AdminManualAttendanceRequest;
use App\Http\Resources\Attendance\StaffAttendanceAdjustmentResource;
use App\Http\Resources\Attendance\StaffAttendanceRecordResource;
use App\Http\Resources\Attendance\StaffAttendanceVerificationLogResource;
use App\Models\StaffAttendanceAdjustment;
use App\Models\StaffAttendanceRecord;
use App\Models\User;
use App\Services\Attendance\StaffAttendanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminStaffAttendanceController extends Controller
{
    public function __construct(
        protected StaffAttendanceService $attendanceService
    ) {}

    protected function authorizeView(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasAnyPermission(PermissionSlug::AttendanceView, PermissionSlug::AttendanceManage), 403, 'Unauthorized.');
    }

    protected function authorizeManage(Request $request): void
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($user->hasPermission(PermissionSlug::AttendanceManage), 403, 'Unauthorized.');
    }

    public function overview(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $overview = $this->attendanceService->getAdminOverview($request->query());

        return ApiResponse::success('Attendance overview retrieved.', $overview);
    }

    public function records(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $perPage = (int) $request->query('per_page', 25);
        $paginator = $this->attendanceService->getAdminAttendanceList($request->query(), $perPage);

        return ApiResponse::paginated(
            'Attendance records retrieved.',
            StaffAttendanceRecordResource::collection($paginator->items())->resolve(),
            $paginator
        );
    }

    public function verificationLogs(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $perPage = (int) $request->query('per_page', 25);
        $paginator = $this->attendanceService->getAdminVerificationLogs($request->query(), $perPage);

        return ApiResponse::paginated(
            'Verification logs retrieved.',
            StaffAttendanceVerificationLogResource::collection($paginator->items())->resolve(),
            $paginator
        );
    }

    public function adjustments(Request $request): JsonResponse
    {
        $this->authorizeView($request);
        $query = StaffAttendanceAdjustment::query()
            ->with(['attendanceRecord.staffProfile.user', 'attendanceRecord.campus', 'requester', 'reviewer'])
            ->latest();

        if ($request->has('status') && $request->query('status') !== '') {
            $query->where('status', $request->query('status'));
        }

        $paginator = $query->paginate((int) $request->query('per_page', 25));

        return ApiResponse::paginated(
            'Attendance adjustments retrieved.',
            StaffAttendanceAdjustmentResource::collection($paginator->items())->resolve(),
            $paginator
        );
    }

    public function reviewAdjustment(Request $request, int $adjustmentId): JsonResponse
    {
        $this->authorizeManage($request);

        $request->validate([
            'approve' => ['required', 'boolean'],
            'review_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $adjustment = $this->attendanceService->reviewAdjustment(
            adjustmentId: $adjustmentId,
            approve: (bool) $request->input('approve'),
            reviewNotes: $request->input('review_notes'),
            reviewer: $request->user()
        );

        $message = $adjustment->status === 'approved'
            ? 'Attendance correction request approved.'
            : 'Attendance correction request rejected.';

        return ApiResponse::success($message, (new StaffAttendanceAdjustmentResource($adjustment))->resolve());
    }

    public function manualRecord(AdminManualAttendanceRequest $request): JsonResponse
    {
        $this->authorizeManage($request);

        $record = $this->attendanceService->manualRecord(
            staffProfileId: (int) $request->validated('staff_profile_id'),
            campusId: (int) $request->validated('campus_id'),
            date: $request->validated('date'),
            clockInAt: $request->validated('clock_in_at'),
            clockOutAt: $request->validated('clock_out_at'),
            status: StaffAttendanceStatus::from($request->validated('status')),
            reason: $request->validated('reason'),
            admin: $request->user()
        );

        return ApiResponse::success(
            'Staff attendance record recorded manually.',
            (new StaffAttendanceRecordResource($record))->resolve(),
            201
        );
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        $this->authorizeView($request);

        $records = $this->attendanceService->getAdminAttendanceList(
            array_merge($request->query(), ['per_page' => 5000]),
            5000
        )->items();

        $filename = 'staff-attendance-'.now('Africa/Lagos')->format('Y-m-d-His').'.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ];

        return response()->stream(function () use ($records) {
            $handle = fopen('php://output', 'w');
            // Write UTF-8 BOM for Excel compatibility
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));

            fputcsv($handle, [
                'Date',
                'Staff Number',
                'Staff Name',
                'Department',
                'Clock In Campus',
                'Clock In',
                'Clock In Distance (m)',
                'Clock Out Campus',
                'Clock Out',
                'Clock Out Distance (m)',
                'Duration (Minutes)',
                'Status',
                'Flagged',
                'Flag Reason',
                'Notes',
            ]);

            /** @var StaffAttendanceRecord $record */
            foreach ($records as $record) {
                fputcsv($handle, [
                    $record->date?->format('Y-m-d'),
                    $record->staffProfile?->staff_number,
                    $record->staffProfile?->user?->name,
                    $record->staffProfile?->department?->name,
                    $record->campus?->name,
                    $record->clock_in_at?->setTimezone('Africa/Lagos')->format('h:i:s A'),
                    $record->clock_in_distance_meters,
                    $record->clockOutCampus?->name ?: $record->campus?->name,
                    $record->clock_out_at?->setTimezone('Africa/Lagos')->format('h:i:s A'),
                    $record->clock_out_distance_meters,
                    $record->durationMinutes(),
                    $record->status?->label(),
                    $record->is_flagged ? 'Yes' : 'No',
                    $record->flag_reason,
                    $record->notes,
                ]);
            }

            fclose($handle);
        }, 200, $headers);
    }
}
