<?php

namespace App\Services\Attendance;

class GeofenceVerificationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly bool $isWithinBoundary,
        public readonly bool $isAccuracyAcceptable,
        public readonly float $distanceMeters,
        public readonly float $accuracy,
        public readonly ?string $rejectionReason = null,
        public readonly bool $isSuspicious = false,
        public readonly ?string $suspiciousReason = null,
    ) {}

    public static function accepted(float $distanceMeters, float $accuracy, bool $isSuspicious = false, ?string $suspiciousReason = null): self
    {
        return new self(
            isValid: true,
            isWithinBoundary: true,
            isAccuracyAcceptable: true,
            distanceMeters: round($distanceMeters, 2),
            accuracy: round($accuracy, 2),
            rejectionReason: null,
            isSuspicious: $isSuspicious,
            suspiciousReason: $suspiciousReason,
        );
    }

    public static function rejected(string $reason, float $distanceMeters, float $accuracy, bool $isWithinBoundary, bool $isAccuracyAcceptable, bool $isSuspicious = false, ?string $suspiciousReason = null): self
    {
        return new self(
            isValid: false,
            isWithinBoundary: $isWithinBoundary,
            isAccuracyAcceptable: $isAccuracyAcceptable,
            distanceMeters: round($distanceMeters, 2),
            accuracy: round($accuracy, 2),
            rejectionReason: $reason,
            isSuspicious: $isSuspicious,
            suspiciousReason: $suspiciousReason,
        );
    }
}

