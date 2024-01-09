<?php

// TODO taylor like doc blocks
return [
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
];
