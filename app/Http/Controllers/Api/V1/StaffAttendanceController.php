<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Attendance\StaffAttendanceCorrectionRequest;
use App\Http\Requests\Attendance\StaffClockInRequest;
use App\Http\Requests\Attendance\StaffClockOutRequest;
use App\Http\Resources\Attendance\StaffAttendanceAdjustmentResource;
use App\Http\Resources\Attendance\StaffAttendanceRecordResource;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\Attendance\StaffAttendanceService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffAttendanceController extends Controller
{
    public function __construct(
        protected StaffAttendanceService $attendanceService
    ) {}

    protected function getStaffProfile(Request $request): StaffProfile
    {
        /** @var User $user */
        $user = $request->user();
        $staff = $user->staffProfile;

        abort_unless($staff instanceof StaffProfile, 403, 'A staff profile is required to access attendance features.');

        return $staff;
    }

    public function overview(Request $request): JsonResponse
    {
        $staff = $this->getStaffProfile($request);
        $overview = $this->attendanceService->getStaffOverview($staff);

        return ApiResponse::success('Staff attendance overview retrieved.', $overview);
    }

    public function clockIn(StaffClockInRequest $request): JsonResponse
    {
        $staff = $this->getStaffProfile($request);

        $record = $this->attendanceService->clockIn(
            staff: $staff,
            campusId: (int) $request->validated('campus_id'),
            latitude: (float) $request->validated('latitude'),
            longitude: (float) $request->validated('longitude'),
            accuracy: (float) $request->validated('accuracy'),
            device: $request->validated('device') ?: $request->userAgent(),
            ip: $request->ip()
        );

        return ApiResponse::success(
            'Clock-in verified and recorded successfully.',
            (new StaffAttendanceRecordResource($record))->resolve(),
            201
        );
    }

    public function clockOut(StaffClockOutRequest $request): JsonResponse
    {
        $staff = $this->getStaffProfile($request);

        $record = $this->attendanceService->clockOut(
            staff: $staff,
            campusId: (int) $request->validated('campus_id'),
            latitude: (float) $request->validated('latitude'),
            longitude: (float) $request->validated('longitude'),
            accuracy: (float) $request->validated('accuracy'),
            device: $request->validated('device') ?: $request->userAgent(),
            ip: $request->ip()
        );

        return ApiResponse::success(
            'Clock-out verified and recorded successfully.',
            (new StaffAttendanceRecordResource($record))->resolve()
        );
    }

    public function history(Request $request): JsonResponse
    {
        $staff = $this->getStaffProfile($request);
        $paginator = $this->attendanceService->getStaffHistory($staff, $request->query());

        return ApiResponse::paginated(
            'Attendance history retrieved.',
            StaffAttendanceRecordResource::collection($paginator->items())->resolve(),
            $paginator
        );
    }

    public function correction(StaffAttendanceCorrectionRequest $request): JsonResponse
    {
        $staff = $this->getStaffProfile($request);

        $requestedValues = array_filter([
            'clock_in_at' => $request->validated('requested_clock_in'),
            'clock_out_at' => $request->validated('requested_clock_out'),
            'status' => $request->validated('requested_status'),
        ]);

        $adjustment = $this->attendanceService->requestCorrection(
            staff: $staff,
            recordId: (int) $request->validated('record_id'),
            requestedValues: $requestedValues,
            reason: $request->validated('reason'),
            user: $request->user()
        );

        return ApiResponse::success(
            'Correction request submitted for administrative review.',
            (new StaffAttendanceAdjustmentResource($adjustment))->resolve(),
            201
        );
    }
}

