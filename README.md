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

You should publish the config file with:

```bash
php artisan vendor:publish --tag="simplestats-client-config"
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

## Bot Detection

Bot and crawler traffic is **filtered out automatically** before any tracking happens. A request is skipped when it has no User-Agent string, or when the User-Agent matches a known bot signature via [matomo/device-detector](https://github.com/matomo-org/device-detector). This is enabled by default and requires no configuration.

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

GNU General Public License v3.0 or later. Please see [License File](LICENSE) for more information.
