<?php

use hisorange\BrowserDetect\ServiceProvider as BrowserDetectServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->app->register(BrowserDetectServiceProvider::class);
});

it('handles referer', function ($referer, $expected) {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    $_SERVER['HTTP_REFERER'] = $referer;

    get('/test', ['user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'])
        ->assertSessionHas('simplestats.tracking.referer', $expected)
        ->assertOk();
})->with([
    'handles https://fake.test' => ['https://fake.test', 'fake.test'],
    'handles https://www.fake.test' => ['https://www.fake.test', 'fake.test'],
    'handles www.fake.test' => ['www.fake.test', 'fake.test'],
    'handles fake.test' => ['fake.test', 'fake.test'],
]);

it('does not track bots', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', ['user_agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)  # Pattern: Googlebot\/ / URL: http://www.google.com/bot.html'])
        ->assertSessionMissing('simplestats.tracking')
        ->assertOk();
});

it('uses public IP from request->ip() directly', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionHas('simplestats.tracking.ip', '8.8.8.8')
        ->assertOk();
});

it('resolves IP from CF-Connecting-IP when request IP is private', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.50',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionHas('simplestats.tracking.ip', '203.0.113.50')
        ->assertOk();
});

it('resolves IP from True-Client-IP when request IP is private', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_TRUE_CLIENT_IP' => '198.51.100.25',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionHas('simplestats.tracking.ip', '198.51.100.25')
        ->assertOk();
});

it('resolves first IP from X-Forwarded-For when request IP is private', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '192.168.1.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 10.0.0.1, 172.16.0.1',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionHas('simplestats.tracking.ip', '203.0.113.99')
        ->assertOk();
});

it('resolves IP from X-Real-IP when request IP is private', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_REAL_IP' => '198.51.100.80',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionHas('simplestats.tracking.ip', '198.51.100.80')
        ->assertOk();
});

it('falls back to request IP when all proxy headers contain private IPs', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '192.168.1.1',
        'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 172.16.0.5',
        'HTTP_X_REAL_IP' => '10.0.0.3',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionHas('simplestats.tracking.ip', '192.168.1.1')
        ->assertOk();
});

it('prioritizes CF-Connecting-IP over other proxy headers', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
        'HTTP_TRUE_CLIENT_IP' => '198.51.100.20',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.30',
        'HTTP_X_REAL_IP' => '198.51.100.40',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionHas('simplestats.tracking.ip', '203.0.113.10')
        ->assertOk();
});

it('blocks resolved proxy IP when it matches blocked IPs', function () {
    Http::fake();
    config(['simplestats-client.blocked_ips' => ['203.0.113.50']]);
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.50',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionMissing('simplestats.tracking')
        ->assertOk();
});

it('skips invalid header values and continues checking next header', function () {
    Http::fake();
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
        'HTTP_TRUE_CLIENT_IP' => '999.999.999.999',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.75',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertSessionHas('simplestats.tracking.ip', '198.51.100.75')
        ->assertOk();
});
