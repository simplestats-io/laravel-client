<?php

// TODO taylor like doc blocks
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Laravel\Paddle\Transaction;

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
            'time_resolver' => fn() => now(),
        ],
        'user' => [
            'model' => User::class,
            'time_resolver' => fn($model) => $model->{$model::CREATED_AT},
        ],
        // TODO set real defaults (maybe null and write this into the docs as an default example for paddle)
        'payment' => [
            'model' => Transaction::class,
            'calculator' => [
                'gross' => fn($model) => $model->total,
                'net' => fn($model) => $model->total - $model->tax - (0.05 * $model->total + 0.50),
            ],
            'user_resolver' => fn($model) => $model->billable,
            'time_resolver' => fn($model) => $model->{$model::CREATED_AT},
        ],
    ]
];
