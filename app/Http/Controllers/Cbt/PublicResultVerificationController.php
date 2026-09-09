<?php

namespace App\Http\Controllers\Cbt;

use App\Http\Controllers\Controller;
use App\Models\CbtResultCheckerPurchase;
use App\Models\SchoolSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Legacy public verification endpoint.
 * Phase 8B account-bound unlock does not issue new verification codes.
 */
class PublicResultVerificationController extends Controller
{
    public function show()
    {
        return redirect('/result/verify');
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $normalized = strtoupper(trim($validated['code']));
        $purchase = CbtResultCheckerPurchase::query()
            ->where(function ($q) use ($normalized) {
                $q->where('verification_code', $normalized)
                    ->orWhere('checker_code', $normalized);
            })
            ->first();

        if ($purchase === null || $purchase->paid_at === null || $purchase->revoked_at !== null) {
            return ApiResponse::error('Verification code is invalid.', status: 404);
        }

        if ($purchase->isExpired()) {
            return ApiResponse::error('This verification code has expired.', status: 410);
        }

        $purchase->loadMissing([
            'result.attempt.exam.subject',
            'result.attempt.exam.academicSession',
            'result.attempt.exam.term',
            'result.attempt.studentProfile',
        ]);
        $exam = $purchase->result?->attempt?->exam;
        $student = $purchase->result?->attempt?->studentProfile;
        $school = SchoolSetting::query()->value('name') ?: config('app.name');
        $name = $student?->fullName() ?? 'Student';

        return ApiResponse::success('VALID RESULT', [
            'status' => 'VERIFIED',
            'school' => $school,
            'student' => $this->maskName($name),
            'examination' => $exam?->title,
            'subject' => $exam?->subject?->name,
            'session' => $exam?->academicSession?->name,
            'term' => $exam?->term?->name,
            'verification_code' => $purchase->verification_code,
        ]);
    }

    private function maskName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if ($parts === []) {
            return 'Student';
        }

        $first = $parts[0];
        $last = $parts[count($parts) - 1] ?? '';
        $firstMasked = mb_substr($first, 0, 1).str_repeat('*', max(0, mb_strlen($first) - 1));
        $lastMasked = $last !== '' && $last !== $first
            ? mb_substr($last, 0, 1).str_repeat('*', max(0, mb_strlen($last) - 1))
            : '';

        return trim($firstMasked.' '.$lastMasked);
    }
}
