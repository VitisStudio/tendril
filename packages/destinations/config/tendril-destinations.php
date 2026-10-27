<?php

/*
| Destinations consume normalized conversions (tendril-marketing); they never
| reshape core events. Credentials come from the environment only. Each
| destination lists the conversion keys it forwards, mapped to the
| destination's own event/action name.
*/

return [

    'destinations' => [

        // 'crm' => [
        //     'driver' => 'webhook',
        //     'url' => env('TENDRIL_WEBHOOK_URL'),
        //     'secret' => env('TENDRIL_WEBHOOK_SECRET'),
        //     'conversions' => ['purchase' => 'purchase', 'consulting-lead' => 'lead'],
        //     'include_click_ids' => false,
        // ],

        // 'meta' => [
        //     'driver' => 'meta',
        //     'pixel_id' => env('TENDRIL_META_PIXEL_ID'),
        //     'access_token' => env('TENDRIL_META_ACCESS_TOKEN'),
        //     'api_version' => env('TENDRIL_META_API_VERSION', 'v21.0'),
        //     'test_event_code' => env('TENDRIL_META_TEST_EVENT_CODE'),
        //     'conversions' => ['purchase' => 'Purchase', 'consulting-lead' => 'Lead'],
        // ],

        // 'google_ads' => [
        //     'driver' => 'google_ads',
        //     'customer_id' => env('TENDRIL_GOOGLE_ADS_CUSTOMER_ID'),
        //     'login_customer_id' => env('TENDRIL_GOOGLE_ADS_LOGIN_CUSTOMER_ID'),
        //     'developer_token' => env('TENDRIL_GOOGLE_ADS_DEVELOPER_TOKEN'),
        //     'client_id' => env('TENDRIL_GOOGLE_ADS_CLIENT_ID'),
        //     'client_secret' => env('TENDRIL_GOOGLE_ADS_CLIENT_SECRET'),
        //     'refresh_token' => env('TENDRIL_GOOGLE_ADS_REFRESH_TOKEN'),
        //     'api_version' => env('TENDRIL_GOOGLE_ADS_API_VERSION', 'v21'),
        //     'conversions' => ['purchase' => 'customers/1234567890/conversionActions/987654321'],
        // ],
    ],

    'batch_size' => 100,

    // Only conversions older than this are sent (late events settle first).
    'settle_minutes' => 5,

    // Window scanned per dispatch run; undelivered conversions older than this are not retried.
    'lookback_hours' => 72,

    'max_attempts' => 5,

    'timeout_seconds' => 10,
];
