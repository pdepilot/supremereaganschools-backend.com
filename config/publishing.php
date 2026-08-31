<?php

return [
    'adsense_enabled' => (bool) env('ADSENSE_ENABLED', false),
    'adsense_client_id' => env('ADSENSE_CLIENT_ID'),
    'adsense_auto_ads' => (bool) env('ADSENSE_AUTO_ADS', false),
    'adsense_verification' => env('ADSENSE_VERIFICATION_CODE'),
    'ads_txt' => env('ADSENSE_ADS_TXT'),
    'analytics_enabled' => (bool) env('ANALYTICS_ENABLED', false),
    'analytics_measurement_id' => env('ANALYTICS_MEASUREMENT_ID'),
];
