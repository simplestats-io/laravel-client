<?php

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use SimpleStatsIo\LaravelClient\Controllers\BeaconController;

if (! config('simplestats-client.enabled') || ! config('simplestats-client.beacon.enabled')) {
    return;
}

$path = BeaconController::path();

if ($path === '') {
    return;
}

// The beacon only works if the tracking middleware runs on it, so it inherits
// the first configured group instead of exposing a second, drift-prone setting.
$group = Arr::first((array) config('simplestats-client.middleware_groups', ['web']), null, 'web');

Route::middleware($group)->get($path, BeaconController::class)->name('simplestats.beacon');
