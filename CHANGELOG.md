# Changelog

All notable changes to `simplestats-client` will be documented in this file.

## Unreleased

### What's changed

* Fixed `SessionTrackingStorage::get()` and `CacheTrackingStorage::get()` returning an array (and triggering a `TypeError`) when stored attribution data is read back. Both now always wrap the value in a `Collection`.

## v5.0.1 - 2026-07-14

### What's changed

* Improved bot detection:
  * Headless browsers (e.g. Headless Chrome) are treated as bots again. They were filtered before v3.5.4 and slipped through after the switch to matomo/device-detector, which classifies them as regular browsers.
  * Requests claiming a modern Chromium browser (Chrome/Edge/Opera 80+) that lack the `Sec-Fetch-Mode` header are treated as bots. Every real Chromium browser sends `Sec-Fetch-*` headers, so this catches scripted clients with faked browser User-Agents.
  * Browser prefetch/prerender requests (`Sec-Purpose`, `Purpose`, `X-Moz` headers) are no longer tracked as visits, since the visitor may never actually see the page.
  

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v5.0.0...v5.0.1

## v5.0.0 - 2026-07-02

### What's changed

* Added **subscription tracking**: `TrackablePayment` now reports whether a payment is part of a subscription, which plan it belongs to and how often it renews. This is the foundation for upcoming recurring revenue metrics (MRR/ARR) and plan-level segmentation.
* Added the `SimpleStatsIo\LaravelClient\Data\TrackingSubscription` value object (a `plan` string and a `SubscriptionInterval`).
* Added the `SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval` enum with `Month` and `Year` cases.
* The `stats-payment` track payload now carries `subscription_interval` (`month`, `year`, or `null`) and `subscription_plan` (free-form string or `null`) fields.

#### Breaking

* `TrackablePayment` gained a required method: `getTrackingSubscription(): ?TrackingSubscription`. Every model implementing `TrackablePayment` (or `TrackablePaymentWithCondition`) must implement it.

#### Upgrade

Add the new method to each of your payment models. For one-time payments just return `null`:

```php
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;

public function getTrackingSubscription(): ?TrackingSubscription
{
    return null; // one-time payment
}


```
For subscription payments, return a `TrackingSubscription` with the plan and interval so we can attribute and segment recurring revenue:

```php
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;

public function getTrackingSubscription(): ?TrackingSubscription
{
    return match ($this->billing_cycle) {
        'monthly' => new TrackingSubscription($this->plan_name, SubscriptionInterval::Month),
        'yearly' => new TrackingSubscription($this->plan_name, SubscriptionInterval::Year),
        default => null,
    };
}


```
The plan is optional, pass `null` if you don't want to track it.

See the [payment tracking docs](https://simplestats.io/docs/how-to-track-a-new-payment.html#subscriptions) for the full walkthrough.

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v4.1.1...v5.0.0

## v4.1.1 - 2026-07-01

### What's Changed

* retry 429 too many requests
* Bump actions/checkout from 6 to 7 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/34

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v4.1.0...v4.1.1

## v4.1.0 - 2026-06-07

### What's changed

* Added **custom properties**: attach your own `name => value` attributes to users and visitors (e.g. `subscription`, `company`, an A/B test variant) and group your analytics by them in the dashboard.
* Added `custom_properties_resolvers` config option with a `user` and a `visitor` slot. Register a class implementing `ResolvesUserCustomProperties` or `ResolvesVisitorCustomProperties` to have properties resolved and sent automatically whenever a user or visitor is tracked.
* Added `SimplestatsClient::trackCustomProperties()` to set properties manually on demand for a user or visitor.
* Visitor properties are inherited by the registration of the same visit, so registrations, conversion rate and revenue can be grouped by properties assigned before sign-up (e.g. an A/B test variant).
* The `stats-visitor` and `stats-user` track payloads now carry an optional `properties` object.
* Added tracking events fired alongside each track: `VisitorTracked`, `UserTracked`, `LoginTracked`, `PaymentTracked`, `CustomEventTracked`, `CustomPropertiesTracked`.
* `trackCustomEvent()` now skips events for visitors that were never tracked (bot, blocked IP, excluded route), since they could never be attributed.

### Upgrade

Upgrading from v4.0 is a no-op. Custom properties are opt-in: nothing changes until you configure a resolver or call `trackCustomProperties()`.

To get started, set in `config/simplestats-client.php`:

```php
'custom_properties_resolvers' => [
    'user' => App\Analytics\UserCustomPropertiesResolver::class,
    'visitor' => App\Analytics\VisitorCustomPropertiesResolver::class,
],




```
See the [custom properties docs](https://simplestats.io/docs/how-to-track-custom-properties.html) for the full walkthrough.

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v4.0.2...v4.1.0

## v4.0.2 - 2026-06-03

### What's changed

* fixed sso login issue

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v4.0.1...v4.0.2

## v4.0.1 - 2026-06-02

### What's changed

* fix own domain referer bug

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v4.0.0...v4.0.1

## v4.0.0 - 2026-05-20

### What's changed

* Added `tracking_storage` config option to support stateless / headless setups (SPAs on a separate domain, mobile apps, JWT or Sanctum token APIs). Choose between `session` (default, classic behavior) and `cache` (visitor identified by a daily IP + User-Agent hash, no session cookie required).
* Added `middleware_groups` config option to control which Laravel middleware groups the `CheckTracking` middleware is appended to (`web`, `api`, or both).
* Removed `api*` from the default `except` array so API routes can be tracked when explicitly opted in via `middleware_groups`.
* Added `SimplestatsClient::getVisitorHash()` and `setVisitorHash()` for custom visitor lookups.
* Dropped support for Laravel 8, 9, 10 and PHP < 8.2. Use the `v3.x` line for those versions.

#### Migration Guide

If you are on Laravel 11+ / PHP 8.2+ and run a classic Blade, Inertia or Livewire app, upgrading to v4 is a no-op. The defaults match the previous behavior.

For headless / SPA / stateless setups, set in `config/simplestats-client.php`:

```php
'middleware_groups' => ['api'],
'tracking_storage' => 'cache',







```
See the [Headless, SPA & Stateless Backends docs](https://simplestats.io/docs/headless-stateless-spa.html) for the full walkthrough.

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.5.5...v4.0.0

## v3.5.5 - 2026-05-15

### What's changed

* improved bot detection

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.5.4...v3.5.5

## v3.5.4 - 2026-05-15

### What's Changed

* new bot detection
* Bump dependabot/fetch-metadata from 3.0.0 to 3.1.0 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/32

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.5.3...v3.5.4

## v3.5.3 - 2026-04-16

### What's changed

* replaced hisorange/browser-detect with jaybizzle/crawler-detect to fix Laravel 13's serializable_classes => false default
  **Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.5.2...v3.5.3

## v3.5.2 - 2026-04-13

### What's Changed

* payload naming fix
* Bump dependabot/fetch-metadata from 2.5.0 to 3.0.0 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/31

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.5.1...v3.5.2

## v3.5.1 - 2026-03-19

### What's changed

* only track logins from trackable persons

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.5.0...v3.5.1

## v3.5.0 - 2026-03-18

### What's Changed

* Bump ramsey/composer-install from 3 to 4 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/25
* Laravel 13 support by @jhhazelaar in https://github.com/simplestats-io/laravel-client/pull/27
* Fix coverage driver error by removing --ci flag from Pest by @jhhazelaar in https://github.com/simplestats-io/laravel-client/pull/28

### New Contributors

* @jhhazelaar made their first contribution in https://github.com/simplestats-io/laravel-client/pull/27

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.4.2...v3.5.0

## v3.4.2 - 2026-03-16

### What's Changed

* fixed ip issues
* Bump actions/checkout from 4 to 5 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/19
* Bump stefanzweifel/git-auto-commit-action from 6 to 7 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/20

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.4.1...v3.4.2

## v3.4.1 - 2026-03-15

### What's changed

* php 7.4 fixes

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.3.0...v3.4.1

## v3.4.0 - 2026-03-15

### What's changed

* improved API error handling

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.3.0...v3.4.0

## v3.3.1 - 2026-03-15

### What's changed

* improved API error handling

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.3.0...v3.3.1

## v3.3.0 - 2026-03-08

### What's Changed

* Custom Events Support

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.2.1...v3.3.0

## v3.2.1 - 2026-02-18

### What's Changed

* Bump dependabot/fetch-metadata from 2.4.0 to 2.5.0 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/24
* dynamically resolve proxy ips

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.2.0...v3.2.1

## v3.2.0 - 2025-12-02

### What's Changed

* Support blocked ips
* Bump aglipanci/laravel-pint-action from 2.5 to 2.6 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/18
* Bump actions/checkout from 4 to 6 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/22

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.1.1...v3.2.0

## v3.1.1 - 2025-07-19

### What's Changed

* fix typos in config comments
* fix phpstan
* Bump dependabot/fetch-metadata from 2.3.0 to 2.4.0 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/16
* Bump stefanzweifel/git-auto-commit-action from 5 to 6 by @dependabot[bot] in https://github.com/simplestats-io/laravel-client/pull/17

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.1.0...v3.1.1

## v3.1.0 - 2025-02-23

### What's Changed

* Bump dependabot/fetch-metadata from 2.2.0 to 2.3.0 by @dependabot in https://github.com/simplestats-io/laravel-client/pull/13
* Bump aglipanci/laravel-pint-action from 2.4 to 2.5 by @dependabot in https://github.com/simplestats-io/laravel-client/pull/14
* Laravel 12 Support

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v3.0.0...v3.1.0

## v3.0.0 - 2024-11-25

### What's changed

* Payments can now also be associated with visitors. Check the docs [here](https://simplestats.io/docs/how-to-track-a-new-payment.html#payment-tracking-for-visitors).

### Migration Guide

If you upgrade to version 3, the only thing you have to do is to rename to `TrackableUser` contract to `TrackablePerson`.
Check the docs [here](https://simplestats.io/docs/how-to-track-a-new-user.html#user-tracking).

## v2.0.14 - 2024-10-31

### What's changed

* added client package version header

## v2.0.13 - 2024-10-30

### What's changed

* `safeDefer` with fallback cause of swoole
* browser detect caching fix (use laravel facade)

## v2.0.12 - 2024-10-30

### What's changed

* when supported: use Laravels `defer` helper to handle tracking api calls to keep the client app fast and make the need of a queue optional

## v2.0.11 - 2024-10-25

### What's changed

* stats user time fallback for tracked logins and payments where no user exists (happens with existing apps, where client hasn't imported user data)

## v2.0.10 - 2024-10-24

### What's changed

* do not track bot user agents test

## v2.0.9 - 2024-10-23

### What's changed

* client side bot detection

## v2.0.7 - 2024-09-14

### What's changed

* added timezone to time parameter

## v2.0.6 - 2024-08-31

### What's changed

* referer without www
* avoid own app url as referer

## v2.0.5 - 2024-08-26

### What's changed

* UTC timestamps
* Added expect option to exclude certain URIs

## v2.0.4 - 2024-08-01

### What's changed

* Apply tracking session only for get requests

## v2.0.3 - 2024-07-30

### What's changed

* Handle referer without https by @tomas-doudera

## v2.0.2 - 2024-07-25

### What's changed

* fixed TrackablePersonWithCondition bug

## v2.0.1 - 2024-07-15

### What's changed

* fixed tracking session return type bug

## v2.0.0 - 2024-07-15

### What's changed

* Visitors tracking
* Referrer tracking
* Entry pages tracking
* Locations tracking (Countries, Regions and Cities)
* Device tracking (Browser, OS and Size)

## v1.0.3 - 2024-04-20

### What's changed

* changed **getTrackingConditionFields** to **watchTrackingFields**

## v1.0.2 - 2024-04-18

### What's Changed

* Bump aglipanci/laravel-pint-action from 2.3.1 to 2.4 by @dependabot in https://github.com/simplestats-io/laravel-client/pull/8

**Full Changelog**: https://github.com/simplestats-io/laravel-client/compare/v1.0.1...v1.0.2

## v1.0.1 - 2024-04-02

### What's changed

* fixed carbon dependency

## v1.0.0 - 2024-04-02

### Initial Release

* SimpleStats client package
