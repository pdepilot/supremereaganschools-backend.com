<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

#[Fillable([
    'adsense_enabled',
    'adsense_client_id',
    'adsense_auto_ads',
    'adsense_verification',
    'analytics_enabled',
    'analytics_measurement_id',
])]
class PublishingSetting extends Model
{
    protected function casts(): array
    {
        return [
            'adsense_enabled' => 'boolean',
            'adsense_auto_ads' => 'boolean',
            'analytics_enabled' => 'boolean',
        ];
    }

    public static function current(): self
    {
        $defaults = [
            'adsense_enabled' => (bool) config('publishing.adsense_enabled'),
            'adsense_client_id' => config('publishing.adsense_client_id'),
            'adsense_auto_ads' => (bool) config('publishing.adsense_auto_ads'),
            'adsense_verification' => config('publishing.adsense_verification'),
            'analytics_enabled' => (bool) config('publishing.analytics_enabled'),
            'analytics_measurement_id' => config('publishing.analytics_measurement_id'),
        ];

        if (! Schema::hasTable((new static)->getTable())) {
            return new static($defaults);
        }

        return static::query()->firstOrCreate(['id' => 1], $defaults);
    }

    public function adsenseEnabled(): bool
    {
        return $this->adsense_enabled || (bool) config('publishing.adsense_enabled');
    }

    public function adsenseAutoAds(): bool
    {
        return $this->adsense_auto_ads || (bool) config('publishing.adsense_auto_ads');
    }

    public function adsenseClientId(): ?string
    {
        $id = trim((string) ($this->adsense_client_id ?: config('publishing.adsense_client_id')));

        if ($id === '' || ! preg_match('/^ca-pub-\d{10,}$/', $id)) {
            return null;
        }

        return $id;
    }

    public function adsenseVerification(): ?string
    {
        $token = trim((string) ($this->adsense_verification ?: config('publishing.adsense_verification')));

        return $token !== '' ? $token : null;
    }

    public function adsenseReady(): bool
    {
        return $this->adsenseEnabled() && $this->adsenseClientId() !== null;
    }

    public function analyticsEnabled(): bool
    {
        return $this->analytics_enabled || (bool) config('publishing.analytics_enabled');
    }

    public function analyticsMeasurementId(): ?string
    {
        $id = trim((string) ($this->analytics_measurement_id ?: config('publishing.analytics_measurement_id')));

        if ($id === '' || ! preg_match('/^G-[A-Z0-9]+$/', $id)) {
            return null;
        }

        return $id;
    }

    public function analyticsReady(): bool
    {
        return $this->analyticsEnabled() && $this->analyticsMeasurementId() !== null;
    }

    public function adsTxtLine(): ?string
    {
        $client = $this->adsenseClientId();

        if ($client === null) {
            return null;
        }

        $publisher = preg_replace('/^ca-/', '', $client);
        $fromConfig = trim((string) config('publishing.ads_txt'));

        if ($fromConfig !== '') {
            if (! str_contains($fromConfig, (string) $publisher)) {
                return null;
            }

            return $fromConfig;
        }

        return 'google.com, '.$publisher.', DIRECT, f08c47fec0942fa0';
    }
}
