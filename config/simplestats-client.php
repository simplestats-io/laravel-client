<?php

// TODO taylor like doc blocks
use App\Models\User;
use Illuminate\Auth\Events\Login;

return [
    /**
     * Do not turn off!!
     */
    'enabled' => env('SIMPLESTATS_ENABLED', true),

    /**
     * API Credentials
     */
    'api_url' => env('SIMPLESTATS_API_URL', 'https://simplestats.com/api/v1/'),
    'api_token' => env('SIMPLESTATS_API_TOKEN'),

    /**
     * Add as much as you like here...
     */
    'tracking_codes' => [
        'source' => ['utm_source', 'ref', 'referer', 'referrer'],
        'medium' => ['utm_medium'],
        'campaign' => ['utm_campaign'],
        'term' => ['utm_term'],
        'content' => ['utm_content'],
    ],

    /**
     * Queue
     */
    'queue' => env('SIMPLESTATS_QUEUE', 'default'),

    /**
     * tracking types
     */
    'tracking_types' => [
        'login' => [
            'event' => Login::class,
        ],
        // Make sure to implement the TrackableUser interface
        'user' => [
            'model' => User::class,
        ],
        // Make sure to implement the TrackablePayment interface
        'payment' => [
            'model' => null,
        ],
    ],
];
