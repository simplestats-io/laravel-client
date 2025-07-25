# Changelog

All notable changes to `simplestats-client` will be documented in this file.

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
