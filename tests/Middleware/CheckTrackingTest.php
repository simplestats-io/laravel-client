<?php

use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->apiUrl = config('simplestats-client.api_url');

    Http::fake([
        $this->apiUrl.'*' => Http::response([], 200),
    ]);

    config([
        'simplestats-client.api_token' => 'foo',
        'simplestats-client.tracking_storage' => 'cache',
    ]);
});

function flushDeferred(): void
{
    if (class_exists(DeferredCallbackCollection::class)) {
        app(DeferredCallbackCollection::class)->invoke();
    }
}

function assertVisitorTrackedWith(string $field, $expected, string $apiUrl): void
{
    flushDeferred();

    Http::assertSent(function ($request) use ($apiUrl, $field, $expected) {
        return $request->url() === $apiUrl.'stats-visitor'
            && ($request->data()[$field] ?? null) === $expected;
    });
}

it('handles referer', function ($referer, $expected) {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    $_SERVER['HTTP_REFERER'] = $referer;

    get('/test', ['user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36'])
        ->assertOk();

    assertVisitorTrackedWith('track_referer', $expected, $this->apiUrl);
})->with([
    'handles https://fake.test' => ['https://fake.test', 'fake.test'],
    'handles https://www.fake.test' => ['https://www.fake.test', 'fake.test'],
    'handles www.fake.test' => ['www.fake.test', 'fake.test'],
    'handles fake.test' => ['fake.test', 'fake.test'],
]);

it('does not track bots', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', ['user_agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)'])
        ->assertOk();

    flushDeferred();
    Http::assertNothingSent();
});

it('does not track requests without a user agent', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    $this->call('GET', '/test', [], [], [], ['HTTP_USER_AGENT' => null])
        ->assertOk();

    flushDeferred();
    Http::assertNothingSent();
});

it('uses public IP from request->ip() directly', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '8.8.8.8', $this->apiUrl);
});

it('resolves IP from CF-Connecting-IP when request IP is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.50',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '203.0.113.50', $this->apiUrl);
});

it('resolves IP from True-Client-IP when request IP is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_TRUE_CLIENT_IP' => '198.51.100.25',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '198.51.100.25', $this->apiUrl);
});

it('resolves first IP from X-Forwarded-For when request IP is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '192.168.1.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 10.0.0.1, 172.16.0.1',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '203.0.113.99', $this->apiUrl);
});

it('resolves IP from X-Real-IP when request IP is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_REAL_IP' => '198.51.100.80',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '198.51.100.80', $this->apiUrl);
});

it('falls back to request IP when all proxy headers contain private IPs', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '192.168.1.1',
        'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 172.16.0.5',
        'HTTP_X_REAL_IP' => '10.0.0.3',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '192.168.1.1', $this->apiUrl);
});

it('prioritizes CF-Connecting-IP over other proxy headers', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
        'HTTP_TRUE_CLIENT_IP' => '198.51.100.20',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.30',
        'HTTP_X_REAL_IP' => '198.51.100.40',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '203.0.113.10', $this->apiUrl);
});

it('blocks resolved proxy IP when it matches blocked IPs', function () {
    config(['simplestats-client.blocked_ips' => ['203.0.113.50']]);
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.50',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    flushDeferred();
    Http::assertNothingSent();
});

it('resolves IP from proxy headers when REMOTE_ADDR is IPv6-mapped private IPv4', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '::ffff:172.17.0.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.50',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '203.0.113.50', $this->apiUrl);
});

it('normalizes IPv6-mapped public IPv4 from request IP', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '::ffff:8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '8.8.8.8', $this->apiUrl);
});

it('finds public IP in X-Forwarded-For chain when first entry is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '192.168.1.1',
        'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 203.0.113.50, 172.16.0.1',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '203.0.113.50', $this->apiUrl);
});

it('skips invalid header values and continues checking next header', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => 'not-an-ip',
        'HTTP_TRUE_CLIENT_IP' => '999.999.999.999',
        'HTTP_X_FORWARDED_FOR' => '198.51.100.75',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '198.51.100.75', $this->apiUrl);
});

it('caches tracking data so a subsequent request can read it from context', function () {
    Route::get('/page', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/page?utm_source=newsletter&utm_campaign=spring-sale', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', 'newsletter', $this->apiUrl);
});

it('reads tracking codes from headers', function () {
    Route::get('/page', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/page', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'HTTP_X_UTM_SOURCE' => 'twitter',
        'HTTP_X_UTM_CAMPAIGN' => 'launch',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', 'twitter', $this->apiUrl);
});

it('reads referer from X-Document-Referer header, overriding $_SERVER[HTTP_REFERER]', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    $_SERVER['HTTP_REFERER'] = 'https://wrong.test';

    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'HTTP_X_DOCUMENT_REFERER' => 'https://google.com',
    ])->assertOk();

    assertVisitorTrackedWith('track_referer', 'google.com', $this->apiUrl);

    unset($_SERVER['HTTP_REFERER']);
});

it('reads referer from document_referer query parameter', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test?document_referer='.urlencode('https://google.com'), [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('track_referer', 'google.com', $this->apiUrl);
});

it('does not bleed a `referer` query param into the document referer (source-alias only)', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test?referer=newsletter', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', 'newsletter', $this->apiUrl);
    assertVisitorTrackedWith('track_referer', null, $this->apiUrl);
});

it('reads page from X-Page header, overriding the request path', function () {
    Route::get('/track-init', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/track-init', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'HTTP_X_PAGE' => '/landing',
    ])->assertOk();

    assertVisitorTrackedWith('page_entry', '/landing', $this->apiUrl);
});

it('reads page from query parameter', function () {
    Route::get('/track-init', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/track-init?page=/pricing', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('page_entry', '/pricing', $this->apiUrl);
});

it('dispatches trackVisitor only once per day per visitor', function () {
    Route::get('/page', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/page', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    flushDeferred();

    get('/page', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    flushDeferred();

    Http::assertSentCount(1);
});

it('falls back to session storage by default', function () {
    config(['simplestats-client.tracking_storage' => 'session']);
    Route::get('/page', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/page?utm_source=newsletter', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])
        ->assertOk()
        ->assertSessionHas('simplestats.tracking');

    assertVisitorTrackedWith('track_source', 'newsletter', $this->apiUrl);
});
