# Laravel Client for SimpleStats.io

[![Latest Version on Packagist](https://img.shields.io/packagist/v/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)
[![Tests](https://github.com/simplestats-io/laravel-client/actions/workflows/run-tests.yml/badge.svg?branch=main)](https://github.com/simplestats-io/laravel-client/actions/workflows/run-tests.yml)
[![Check & fix styling](https://github.com/simplestats-io/laravel-client/actions/workflows/fix-php-code-style-issues.yml/badge.svg?branch=main)](https://github.com/simplestats-io/laravel-client/actions/workflows/fix-php-code-style-issues.yml)
[![License](https://img.shields.io/packagist/l/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)
<!--[![Total Downloads](https://img.shields.io/packagist/dt/simplestats-io/laravel-client.svg?style=flat-square)](https://packagist.org/packages/simplestats-io/laravel-client)-->

This is the official Laravel client to send tracking data to [https://simplestats.io](https://simplestats.io)

## Introduction

_**SimpleStats**_ is a streamlined statistics tool tailored for **Laravel** applications, transcending mere counts of visits, views, and page impressions. It offers **precise insights** into user origins and behaviors. With default tracking and filtering via [UTM](https://en.wikipedia.org/wiki/UTM_parameters) codes, you gain detailed analysis of **marketing** campaigns, identifying which efforts drive **revenue**. Effortlessly evaluate campaign **ROI**, discover cost-effective user acquisition channels, and pinpoint the most effective performance channels. _SimpleStats_ ensures full **GDPR compliance** and a minimalistic and straightforward installation process.

![screenshot](https://github.com/simplestats-io/laravel-client/assets/7384870/e513643b-3adb-475f-8a17-ba4bdd53f0fa)

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
        // the TrackableUserWithCondition contract
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
```

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
