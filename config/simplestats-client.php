<?php

use App\Models\User;
use Illuminate\Auth\Events\Login;

return [

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Settings
     |--------------------------------------------------------------------------
     |
     | SimpleStats is enabled by default. Be aware that if you turn disable it,
     | you may lose important tracking data. In most cases, leave it enabled!
     |
     */

    'enabled' => env('SIMPLESTATS_ENABLED', true),

    /*
     |--------------------------------------------------------------------------
     | SimpleStats API Credentials
     |--------------------------------------------------------------------------
     |
     | Define your API credentials here. If you are not told to change the API URL,
     | just keep the default. It's important to set an API token! You'll receive
     | one, after creating an Instance for a Project on https://simplestats.io
     |
     */

    'api_url' => env('SIMPLESTATS_API_URL', 'https://simplestats.io/api/v1/'),

    'api_token' => env('SIMPLESTATS_API_TOKEN'),

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Queue
     |--------------------------------------------------------------------------
     |
     | To avoid the tracking API calls block the whole request and for fault tolerance,
     | we highly recommend to use Laravel's built-in queue-system. Here you can define
     | to which queue the tracking API calls should be dispatched and handled by.
     |
     */

    'queue' => env('SIMPLESTATS_QUEUE', 'default'),

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Tracking Codes
     |--------------------------------------------------------------------------
     |
     | Below you can set your tracking code URL param names. We already set some
     | classical defaults for you, but you're free to change them as you like.
     | Note that only the params which are listed here are getting tracked!
     |
     */

    'tracking_codes' => [
        'source' => ['utm_source', 'ref', 'referer', 'referrer'],
        'medium' => ['utm_medium', 'adGroup', 'adGroupId'],
        'campaign' => ['utm_campaign'],
        'term' => ['utm_term'],
        'content' => ['utm_content'],
    ],

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Tracking Types
     |--------------------------------------------------------------------------
     |
     | Here you can set three different tracking types. The first is the login
     | event. If this event gets dispatched, we track a login. The second is
     | the user model. If such a model is created, we track a registration.
     |
     | As the payment model is named very individually, we did not set any default here.
     | Give it the name of the model which holds your payments or transactions data.
     |
     | See: https://simplestats.io/docs
     |
     */

    'tracking_types' => [
        'login' => [
            'event' => Login::class,
        ],

        // Make sure this model implements the TrackableUser or
        // the TrackableUserWithCondition Contract
        'user' => [
            'model' => User::class,
        ],

        // Make sure this model implements the TrackablePayment or
        // the TrackablePaymentWithCondition contract
        'payment' => [
            'model' => null,
        ],
    ],
];
