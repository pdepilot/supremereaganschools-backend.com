<?php

namespace App\Services\Attendance;

use App\Models\Campus;
use App\Models\StaffAttendanceVerificationLog;
use App\Models\StaffProfile;

class GeofenceService
{
    public const EARTH_RADIUS_METERS = 6371000.0;

    /**
     * Calculate the great-circle distance between two geographic coordinates using the Haversine formula.
     */
    public function haversineDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $lat1Rad = deg2rad($lat1);
        $lon1Rad = deg2rad($lon1);
        $lat2Rad = deg2rad($lat2);
        $lon2Rad = deg2rad($lon2);

        $deltaLat = $lat2Rad - $lat1Rad;
        $deltaLon = $lon2Rad - $lon1Rad;

        $a = sin($deltaLat / 2) ** 2 + cos($lat1Rad) * cos($lat2Rad) * (sin($deltaLon / 2) ** 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Ray-casting algorithm to determine if a point is inside a polygon.
     *
     * @param list<array{latitude: float, longitude: float}|array{lat: float, lng: float}> $polygon
     */
    public function isPointInPolygon(float $lat, float $lon, array $polygon): bool
    {
        $inside = false;
        $count = count($polygon);

        if ($count < 3) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = (float) ($polygon[$i]['latitude'] ?? $polygon[$i]['lat'] ?? 0);
            $yi = (float) ($polygon[$i]['longitude'] ?? $polygon[$i]['lng'] ?? 0);
            $xj = (float) ($polygon[$j]['latitude'] ?? $polygon[$j]['lat'] ?? 0);
            $yj = (float) ($polygon[$j]['longitude'] ?? $polygon[$j]['lng'] ?? 0);

            $denominator = ($yj - $yi) !== 0.0 ? ($yj - $yi) : 0.00000001;
            $intersect = (($yi > $lon) !== ($yj > $lon))
                && ($lat < ($xj - $xi) * ($lon - $yi) / $denominator + $xi);

            if ($intersect) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    /**
     * Verify whether a location reading is valid, within bounds, meets accuracy criteria, and is clean of spoofing.
     */
    public function verify(
        Campus $campus,
        float $latitude,
        float $longitude,
        float $accuracy,
        ?StaffProfile $staff = null
    ): GeofenceVerificationResult {
        // 1. Validate coordinate format and boundaries
        if ($latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0) {
            return GeofenceVerificationResult::rejected(
                'Invalid GPS coordinates received.',
                0.0,
                $accuracy,
                false,
                false,
                true,
                'Coordinate out of global range.'
            );
        }

        // Null Island detection (lat 0, lon 0)
        if (abs($latitude) < 0.0001 && abs($longitude) < 0.0001) {
            return GeofenceVerificationResult::rejected(
                'Invalid GPS location detected (Null Island). Please enable device GPS.',
                0.0,
                $accuracy,
                false,
                false,
                true,
                'Null Island coordinates (0,0).'
            );
        }

        // 2. Validate campus configuration
        if (! $campus->hasValidCoordinates()) {
            return GeofenceVerificationResult::rejected(
                'Selected campus has no GPS location configured. Please contact the administrator.',
                0.0,
                $accuracy,
                false,
                false
            );
        }

        $distance = $this->haversineDistance($latitude, $longitude, $campus->latitude, $campus->longitude);

        // 3. Accuracy check
        $threshold = (float) ($campus->gps_accuracy_threshold ?: 50);
        $isAccuracyAcceptable = ($accuracy > 0.0 && $accuracy <= $threshold);

        // 4. Boundary check
        $isWithinBoundary = false;
        if ($campus->geofence_type === 'polygon') {
            $coords = $campus->boundary_coordinates;
            if (empty($coords) || ! is_array($coords) || count($coords) < 3) {
                return GeofenceVerificationResult::rejected(
                    'Campus polygon geofence configuration is invalid or has fewer than 3 boundary coordinates. Please contact the administrator.',
                    $distance,
                    $accuracy,
                    false,
                    $isAccuracyAcceptable
                );
            }

            // Polygon boundary is strictly authoritative; no circular radius fallback.
            $isWithinBoundary = $this->isPointInPolygon($latitude, $longitude, $coords);
        } else {
            $radius = (float) ($campus->radius_meters ?: 100);
            $isWithinBoundary = ($distance <= $radius);
        }

        // 5. Anti-spoofing heuristics
        $isSuspicious = false;
        $suspiciousReason = null;

        // Extreme precision anomaly (often mock apps)
        if ($accuracy <= 0.05) {
            $isSuspicious = true;
            $suspiciousReason = 'Unnaturally precise GPS reading detected (< 0.05m accuracy).';
        }

        // Velocity check against recent attempts
        if ($staff) {
            $recentLog = StaffAttendanceVerificationLog::query()
                ->where('staff_profile_id', $staff->id)
                ->where('attempted_at', '>=', now()->subMinutes(15))
                ->latest('attempted_at')
                ->first();

            if ($recentLog && $recentLog->latitude !== null && $recentLog->longitude !== null) {
                $elapsedSeconds = max(1, abs(now()->diffInSeconds($recentLog->attempted_at)));
                if ($elapsedSeconds < 900) {
                    $transitDistance = $this->haversineDistance($latitude, $longitude, $recentLog->latitude, $recentLog->longitude);
                    $speedMps = $transitDistance / $elapsedSeconds;

                    // Over 50 m/s (~180 km/h) over a significant distance (> 1000m)
                    if ($speedMps > 50.0 && $transitDistance > 1000.0) {
                        $isSuspicious = true;
                        $suspiciousReason = sprintf(
                            'Impossible transit velocity detected (%.0fm in %ds = %.1f km/h).',
                            $transitDistance,
                            $elapsedSeconds,
                            $speedMps * 3.6
                        );
                    }
                }
            }
        }

        // 6. Outcome
        if (! $isAccuracyAcceptable) {
            return GeofenceVerificationResult::rejected(
                sprintf(
                    'GPS accuracy is too low (%.1fm). Maximum allowable threshold is %.0fm. Please ensure device high accuracy location is turned on and move to an area with clear sky view.',
                    $accuracy,
                    $threshold
                ),
                $distance,
                $accuracy,
                $isWithinBoundary,
                false,
                $isSuspicious,
                $suspiciousReason
            );
        }

        if (! $isWithinBoundary) {
            $boundaryDescription = $campus->geofence_type === 'polygon'
                ? 'outside the approved campus polygon boundary'
                : sprintf('outside the approved campus boundary (%.0fm away; permitted radius is %dm)', $distance, (int) ($campus->radius_meters ?: 100));

            return GeofenceVerificationResult::rejected(
                sprintf('You are %s.', $boundaryDescription),
                $distance,
                $accuracy,
                false,
                true,
                $isSuspicious,
                $suspiciousReason
            );
        }

        return GeofenceVerificationResult::accepted(
            $distance,
            $accuracy,
            $isSuspicious,
            $suspiciousReason
        );
    }
}
