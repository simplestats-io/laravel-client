# Laravel Client for SimpleStats.io

[![Latest Version on Packagist](https://img.shields.io/packagist/v/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)
[![Tests](https://github.com/simplestats-io/laravel-client/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/simplestats-io/laravel-client/actions/workflows/run-tests.yml)
[![Check & fix styling](https://github.com/simplestats-io/laravel-client/actions/workflows/fix-php-code-style-issues.yml/badge.svg?branch=main)](https://github.com/simplestats-io/laravel-client/actions/workflows/fix-php-code-style-issues.yml)
[![License](https://img.shields.io/packagist/l/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)
[![Laravel Compatibility](https://badge.laravel.cloud/badge/simplestats-io/laravel-client)](https://packagist.org/packages/simplestats-io/laravel-client)
<!--[![Total Downloads](https://img.shields.io/packagist/dt/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)-->

This is the official Laravel client to send tracking data to [https://simplestats.io](https://simplestats.io)

## Introduction

_**SimpleStats**_ is server-side **analytics for Laravel** that follows the whole funnel: from the first **visit**, through **registration**, to every **payment**, tied back to the [UTM](https://en.wikipedia.org/wiki/UTM_parameters) source or referrer that caused it. So you see not just traffic, but which **channels** actually drive **revenue**.

That is the part no other tool covers: web analytics (Plausible, Fathom) never see your payments, and subscription analytics (Baremetrics, ProfitWell) never see your acquisition channels. SimpleStats sits in between and goes deeper where it matters: **MRR / ARR**, **churn** and **retention** for subscription apps, and **ROAS / CAC / LTV:CAC** once you add your ad spend per campaign. It is fully **GDPR compliant**, **ad-blocker proof**, and ships with a minimalistic, straightforward installation process.

![screenshot](https://simplestats.io/images/screenshot.png)

## Installation

You can install the client package via composer:

```bash
composer require simplestats-io/laravel-client
```

You should publish the config file with the install command:

```bash
php artisan simplestats-client:install
```

This is the default content of the config file, tweak it to your needs:

```php
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
```

## Page Views

Since v6 the client also counts the pages each tracked visitor opens, so every channel, campaign and entry page shows how deep its visitors browse, and you get a list of your top pages. A long-running queue worker (`queue:work`, Horizon) bundles the views into one API request per minute, without one every view is sent on its own. Check your setup with:

```bash
php artisan simplestats:status
```

The command exits with a non-zero code when something is broken (missing token, API not reachable), so it also works as a health check.

## Upgrading to v6

v6 turns page views on by default. This also applies to config files published before v6, because the `page_views` block is merged in from the package defaults. What changes for you:

- **One small queue job per page view.** A long-running queue worker bundles them, so the API sees about one request per worker and minute. With `queue:listen`, `queue:work --once`, Vapor or without the `pcntl` extension every view is sent on its own. You can move the jobs to a queue of their own with `SIMPLESTATS_PAGE_VIEWS_QUEUE`, and Horizon users may want to add `SimpleStatsIo\LaravelClient\Jobs\SendPageViews` to `horizon.silenced`.
- **Nothing changes on the `sync` queue.** While your queue connection is `sync`, `deferred` or `background`, page views stay off, because every view would cost an API request inside your web request. Set `SIMPLESTATS_PAGE_VIEWS_SYNC=true` to send them anyway (one request per view, no retries).
- **Page views do not count against your monthly event quota.** Once the quota is reached, the client pauses sending views until the API accepts them again.
- **Logged-in users count on public pages only.** Routes behind a login middleware (`auth`, `auth:*`, every subclass of Laravel's `Authenticate` middleware, e.g. Filament panels) are skipped unless you set `count_authenticated_routes` to `true`.
- **Your tests may see new jobs.** With a real queue connection in your test environment, every tracked GET request now also pushes a `SendPageViews` job. Assertions like `Queue::assertNothingPushed()` may need `SIMPLESTATS_PAGE_VIEWS=false` in your `phpunit.xml`.
- **Registrations, custom events and payments keep their visitor after midnight.** With the `session` tracking storage they now reference the visitor hash the visit was first reported with, instead of a hash recomputed per request, which the API did not know after midnight or an IP change.
- If you override `CheckTracking::handle()` without calling the parent, no page views are counted. To exclude further requests, override `doViewTracking()` or use the `page_views.except` option.

To turn page views off, set `SIMPLESTATS_PAGE_VIEWS=false`.

## Bot Detection

Bot and crawler traffic is **filtered out automatically** before any tracking happens. This covers known bot signatures via [matomo/device-detector](https://github.com/matomo-org/device-detector), HTTP libraries (curl, python-requests, ...), headless browsers, scripted clients faking a real browser, and browser prefetch requests. This is enabled by default and requires no configuration.

## Static Caching

If your pages are served from a cache that bypasses PHP entirely (Statamic `full` measure static caching, nginx/Varnish page caching, Cloudflare "Cache Everything"), the tracking middleware never runs. Enable the `beacon` config option and add the snippet from [Static Caching & CDNs](https://simplestats.io/docs/static-caching) to your layout.

## Documentation

Check out the full documentation here: [Official SimpleStats.io Documentation](https://simplestats.io/docs)

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Zacharias Creutznacher](https://github.com/sairahcaz)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
