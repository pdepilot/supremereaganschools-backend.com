<?php

namespace App\Http\Controllers\Api\V1\News;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\PublishingSetting;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublishingSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $this->authorize('manageSeo', Post::class);

        return ApiResponse::success('Publishing settings retrieved.', $this->payload(PublishingSetting::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $this->authorize('manageSeo', Post::class);

        $data = $request->validate([
            'adsense_enabled' => ['nullable', 'boolean'],
            'adsense_client_id' => ['nullable', 'string', 'max:40', 'regex:/^$|^ca-pub-\d{10,}$/'],
            'adsense_auto_ads' => ['nullable', 'boolean'],
            'adsense_verification' => ['nullable', 'string', 'max:400'],
            'analytics_enabled' => ['nullable', 'boolean'],
            'analytics_measurement_id' => ['nullable', 'string', 'max:40'],
        ]);

        $settings = PublishingSetting::current();
        $settings->fill($data);
        $settings->save();

        return ApiResponse::success('Publishing settings saved.', $this->payload($settings->fresh() ?? $settings));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PublishingSetting $settings): array
    {
        return [
            'adsense_enabled' => $settings->adsenseEnabled(),
            'adsense_client_id' => $settings->adsenseClientId() ?? $settings->adsense_client_id,
            'adsense_auto_ads' => $settings->adsenseAutoAds(),
            'adsense_verification' => $settings->adsenseVerification(),
            'adsense_ready' => $settings->adsenseReady(),
            'analytics_enabled' => $settings->analyticsEnabled(),
            'analytics_measurement_id' => $settings->analyticsMeasurementId() ?? $settings->analytics_measurement_id,
        ];
    }
}
