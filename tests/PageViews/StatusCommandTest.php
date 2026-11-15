<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SimpleStatsIo\LaravelClient\Services\PageViewBuffer;

beforeEach(function () {
    $this->apiStatus = 422;
    $this->apiBody = ['message' => 'The views field is required.'];

    Http::fake(fn () => Http::response($this->apiBody, $this->apiStatus));
});

it('reports a healthy setup with an active worker', function () {
    config(['queue.default' => 'database']);
    Cache::put(PageViewBuffer::HEARTBEAT_CACHE_KEY, ['at' => now()->getTimestamp() - 12, 'buffering' => true], 3600);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('reachable, token valid')
        ->expectsOutputToContain(extension_loaded('pcntl') ? 'queue worker seen 12 s ago' : 'pcntl')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_ends_with($request->url(), 'stats-views') && $request['views'] === []);
});

it('shows page views as unbundled without a recent worker', function () {
    config(['queue.default' => 'database']);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('unbundled')
        ->assertSuccessful();
});

it('hints at the sync opt-in without failing', function () {
    config(['queue.default' => 'sync']);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('SIMPLESTATS_PAGE_VIEWS_SYNC=true')
        ->assertSuccessful();
});

it('shows disabled page views', function () {
    config(['simplestats-client.page_views.enabled' => false]);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('SIMPLESTATS_PAGE_VIEWS=false')
        ->assertSuccessful();
});

it('shows an active pause', function () {
    config(['queue.default' => 'database']);
    Cache::put(PageViewBuffer::PAUSE_CACHE_KEY, now()->getTimestamp() + 600, 600);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('monthly event limit is reached')
        ->assertSuccessful();
});

it('fails when the API rejects the token or cannot be reached', function (int $status) {
    $this->apiStatus = $status;

    $this->artisan('simplestats:status')->assertFailed();
})->with([401, 404, 500]);

it('fails without an API token', function () {
    config(['simplestats-client.api_token' => null]);

    $this->artisan('simplestats:status')
        ->expectsOutputToContain('SIMPLESTATS_API_TOKEN')
        ->assertFailed();

    Http::assertNothingSent();
});
