<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'paystack' => [
        'public_key' => env('PAYSTACK_PUBLIC_KEY'),
        'secret_key' => env('PAYSTACK_SECRET_KEY'),
        'base_url' => env('PAYSTACK_BASE_URL', 'https://api.paystack.co'),
        'currency' => env('PAYSTACK_CURRENCY', 'NGN'),
        'callback_url' => env('PAYSTACK_CALLBACK_URL'),
        'webhook_secret' => env('PAYSTACK_SECRET_KEY'),
        'test_amount_kobo' => (int) env('PAYSTACK_TEST_AMOUNT_KOBO', 10000),
        'test_payments_enabled' => (bool) env('PAYSTACK_TEST_PAYMENTS_ENABLED', false),
    ],

    'cbt_result_checker' => [
        'amount_kobo' => (int) env('CBT_RESULT_CHECKER_AMOUNT_KOBO', 50000),
        'currency' => env('CBT_RESULT_CHECKER_CURRENCY', env('PAYSTACK_CURRENCY', 'NGN')),
    ],

    'admission_application_fee' => [
        'amount_kobo' => (int) env('ADMISSION_APPLICATION_FEE_KOBO', 500000),
        'currency' => env('ADMISSION_APPLICATION_FEE_CURRENCY', env('PAYSTACK_CURRENCY', 'NGN')),
    ],

];
