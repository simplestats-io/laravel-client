<?php

use App\Models\User;
use Illuminate\Auth\Events\Login;

return [

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Settings
     |--------------------------------------------------------------------------
     |
     | SimpleStats is enabled by default. Disabling it will stop tracking your stats.
     |
     | You can provide an array of URI's that must be ignored (eg. 'api/*')
     */

    'enabled' => env('SIMPLESTATS_ENABLED', true),

    'except' => [
        'telescope*',
        'horizon*',
        'pulse*',
        'admin*',
    ],

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Blocked IPs
     |--------------------------------------------------------------------------
     |
     | Define IP addresses or CIDR ranges that should be excluded from tracking.
     | Supports single IPs (e.g. '192.168.1.1') and CIDR notation (e.g. '10.0.0.0/8').
     |
     */

    'blocked_ips' => [
        // '192.168.1.1',
        // '10.0.0.0/8',
        // '172.16.0.0/12',
    ],

    /*
     |--------------------------------------------------------------------------
     | SimpleStats API Credentials
     |--------------------------------------------------------------------------
     |
     | Define your API credentials here. If you are not told to change the API URL,
     | just keep the default. It's important to set an API token! You'll receive
     | one, after creating your team and project on https://simplestats.io
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
     | we highly recommend using Laravel's built-in queue-system. Here you can define
     | to which queue the tracking API calls should be dispatched and handled by.
     |
     */

    'queue' => env('SIMPLESTATS_QUEUE', 'default'),

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Log Errors
     |--------------------------------------------------------------------------
     |
     | When enabled, the client will log API error responses (4xx, 5xx) to your
     | application log on every failed attempt. Transient errors like connection
     | timeouts are only logged on the final retry attempt.
     |
     */

    'log_errors' => env('SIMPLESTATS_LOG_ERRORS', false),

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Middleware Groups
     |--------------------------------------------------------------------------
     |
     | Which middleware group(s) the CheckTracking middleware is appended to.
     | Pick what matches your setup:
     |
     | - ['web']         : classic server-rendered apps (Blade, Inertia, Livewire)
     | - ['api']         : headless / SPA frontends that only ever hit your API
     | - ['web', 'api']  : hybrid setups (e.g. Blade marketing + SPA dashboard)
     |
     */

    'middleware_groups' => ['web'],

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Tracking Storage
     |--------------------------------------------------------------------------
     |
     | Where to persist a visitor's attribution (UTMs, referer, entry page) so
     | it survives across requests in the same day.
     |
     | - 'session' (default): stores the data in the user's session. Requires a
     |   session cookie and works only in classic, session-bound Laravel apps.
     |   Survives mobile IP changes because the cookie identifies the user.
     |
     | - 'cache': stores the data in Laravel's cache keyed by a daily hash of
     |   IP + User-Agent. Works in stateless / headless setups (JWT, SPA on a
     |   separate domain) where no session cookie is available. Loses precision
     |   when the visitor's IP changes (mobile network switches), which is the
     |   same trade-off privacy-first tools like Plausible and Fathom make to
     |   stay cookie- and consent-free.
     |
     */

    'tracking_storage' => 'session',

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Beacon
     |--------------------------------------------------------------------------
     |
     | Only needed if your pages are served from a static cache that bypasses
     | PHP entirely (Statamic "full measure" static caching, nginx/Varnish page
     | caching, Cloudflare "Cache Everything", ...). In that case the tracking
     | middleware never runs and those visits are lost.
     |
     | Enabling this registers a tiny GET endpoint that returns a 1x1 pixel.
     | Cached pages request it once per page view, which gives the middleware
     | a real request to track. Add the snippet from the docs to your layout:
     |
     | https://simplestats.io/docs/static-caching
     |
     | The route is registered in the first group listed in middleware_groups,
     | so it always carries the tracking middleware.
     |
     | Pick a path that looks like a regular asset: paths containing "track",
     | "stats", "analytics", "beacon", "collect" or "pixel" are blocked by
     | common ad blocker lists, and a path with a file extension may be cached
     | by your CDN before it ever reaches your app.
     |
     */

    'beacon' => [
        'enabled' => env('SIMPLESTATS_BEACON_ENABLED', false),
        'path' => env('SIMPLESTATS_BEACON_PATH', 'assets/v'),
    ],

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Page Views
     |--------------------------------------------------------------------------
     |
     | Counts the pages each tracked visitor opens and maps them to that visitor,
     | so every channel shows how deep its visitors browse, plus your top pages.
     |
     | What counts: successful GET page loads, meaning full HTML documents,
     | Inertia visits, Livewire wire:navigate, Turbo Drive and htmx boosted
     | visits, and beacon or forwarded page pings. What never counts: partial
     | reloads, polling, redirects, error pages, JSON, downloads, browser and
     | Inertia prefetches, and visitors that were not tracked (bots, blocked
     | IPs, the "except" list above). The same page twice in a row counts once.
     |
     | Every view is one small job on your queue. A long-running queue worker
     | (queue:work, Horizon) bundles them into one API request per minute.
     | Without such a worker every view is sent on its own. Run
     | "php artisan simplestats:status" to see which mode you are in.
     |
     | - 'sync': page views are off while your queue connection is "sync",
     |   "deferred" or "background", because every view would then cost an
     |   API request inside your web request. Set it to true to send them anyway.
     |
     | - 'buffer': 'auto' bundles inside long-running queue workers (needs the
     |   pcntl extension), 'none' always sends every view on its own.
     |
     | - 'flush_interval': seconds a view may wait in the worker before the
     |   bundle is sent (5 to 300).
     |
     | - 'max_per_visitor_day': upper limit of counted views per visitor and day,
     |   a safety net against loops, wallboards and scrapers.
     |
     | - 'queue': a dedicated queue for page views, so they never wait behind
     |   long-running jobs. Defaults to the "queue" option above.
     |
     | - 'count_authenticated_routes': views on routes behind a login middleware
     |   (auth, auth:*, Filament panels, ...) are app usage and do not count by
     |   default. Logged-in users still count on all other pages.
     |
     | - 'only' / 'except': URI patterns (same syntax as the "except" list
     |   above) to narrow down which pages count as views. They never affect
     |   whether a visitor is tracked. An empty 'only' means all pages.
     |
     */

    'page_views' => [
        'enabled' => env('SIMPLESTATS_PAGE_VIEWS', true),
        'sync' => env('SIMPLESTATS_PAGE_VIEWS_SYNC', false),
        'buffer' => env('SIMPLESTATS_PAGE_VIEWS_BUFFER', 'auto'),
        'flush_interval' => (int) env('SIMPLESTATS_PAGE_VIEWS_FLUSH_INTERVAL', 60),
        'max_per_visitor_day' => 100,
        'queue' => env('SIMPLESTATS_PAGE_VIEWS_QUEUE'),
        'count_authenticated_routes' => false,
        'only' => [],
        'except' => [],
    ],

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
        'source' => ['utm_source', 'ref'],
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

        // Make sure this model implements the TrackablePerson or
        // the TrackablePersonWithCondition Contract
        'user' => [
            'model' => User::class,
        ],

        // Make sure this model implements the TrackablePayment or
        // the TrackablePaymentWithCondition contract
        'payment' => [
            'model' => null,
        ],
    ],

    /*
     |--------------------------------------------------------------------------
     | SimpleStats Custom Properties Resolvers
     |--------------------------------------------------------------------------
     |
     | Optionally set classes that resolve custom properties whenever a user
     | or a visitor is tracked (e.g. an A/B test variant). The resolved
     | properties are sent along with the track itself.
     |
     | The user resolver must implement the ResolvesUserCustomProperties
     | contract, the visitor resolver the ResolvesVisitorCustomProperties
     | contract.
     |
     */

    'custom_properties_resolvers' => [
        'user' => null,
        'visitor' => null,
    ],
];
