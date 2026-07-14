<?php

use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\AbTestPropertiesResolver;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\InvalidPropertiesResolver;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\ThrowingVisitorPropertiesResolver;

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

    get('/test', [
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'referer' => $referer,
    ])->assertOk();

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

it('does not track headless browsers', function ($userAgent) {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'user_agent' => $userAgent,
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    flushDeferred();
    Http::assertNothingSent();
})->with([
    'Headless Chrome' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/125.0.0.0 Safari/537.36',
    'Headless Edge' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) HeadlessChrome/120.0.0.0 Safari/537.36 Edg/120.0.0.0',
]);

it('does not track modern Chromium user agents without Sec-Fetch headers', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
    ])->assertOk();

    flushDeferred();
    Http::assertNothingSent();
});

it('tracks non-Chromium browsers without Sec-Fetch headers', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15',
    ])->assertOk();

    assertVisitorTrackedWith('page_entry', '/test', $this->apiUrl);
});

it('tracks pre-Sec-Fetch Chrome versions without Sec-Fetch headers', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/79.0.3945.130 Safari/537.36',
    ])->assertOk();

    assertVisitorTrackedWith('page_entry', '/test', $this->apiUrl);
});

it('does not track prefetch requests', function ($headers) {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        ...$headers,
    ])->assertOk();

    flushDeferred();
    Http::assertNothingSent();
})->with([
    'Sec-Purpose prefetch' => [['sec_purpose' => 'prefetch']],
    'Sec-Purpose prefetch;prerender' => [['sec_purpose' => 'prefetch;prerender']],
    'Purpose prefetch' => [['purpose' => 'prefetch']],
    'X-Moz prefetch' => [['x_moz' => 'prefetch']],
]);

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
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '8.8.8.8', $this->apiUrl);
});

it('resolves IP from CF-Connecting-IP when request IP is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '172.17.0.1',
        'HTTP_CF_CONNECTING_IP' => '203.0.113.50',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '203.0.113.50', $this->apiUrl);
});

it('resolves IP from True-Client-IP when request IP is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '10.0.0.1',
        'HTTP_TRUE_CLIENT_IP' => '198.51.100.25',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '198.51.100.25', $this->apiUrl);
});

it('resolves first IP from X-Forwarded-For when request IP is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '192.168.1.1',
        'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 10.0.0.1, 172.16.0.1',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '203.0.113.99', $this->apiUrl);
});

it('resolves IP from X-Real-IP when request IP is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '10.0.0.5',
        'HTTP_X_REAL_IP' => '198.51.100.80',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
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
        'sec_fetch_mode' => 'navigate',
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
        'sec_fetch_mode' => 'navigate',
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
        'sec_fetch_mode' => 'navigate',
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
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '203.0.113.50', $this->apiUrl);
});

it('normalizes IPv6-mapped public IPv4 from request IP', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '::ffff:8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '8.8.8.8', $this->apiUrl);
});

it('finds public IP in X-Forwarded-For chain when first entry is private', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '192.168.1.1',
        'HTTP_X_FORWARDED_FOR' => '10.0.0.2, 203.0.113.50, 172.16.0.1',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
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
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('ip', '198.51.100.75', $this->apiUrl);
});

it('caches tracking data so a subsequent request can read it from context', function () {
    Route::get('/page', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/page?utm_source=newsletter&utm_campaign=spring-sale', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', 'newsletter', $this->apiUrl);
});

it('reads tracking codes from headers', function () {
    Route::get('/page', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/page', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'HTTP_X_UTM_SOURCE' => 'twitter',
        'HTTP_X_UTM_CAMPAIGN' => 'launch',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', 'twitter', $this->apiUrl);
});

it('reads referer from X-Document-Referer header, overriding the standard Referer header', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'referer' => 'https://wrong.test',
        'HTTP_X_DOCUMENT_REFERER' => 'https://google.com',
    ])->assertOk();

    assertVisitorTrackedWith('track_referer', 'google.com', $this->apiUrl);
});

it('reads referer from document_referer query parameter', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test?document_referer='.urlencode('https://google.com'), [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('track_referer', 'google.com', $this->apiUrl);
});

it('ignores `referer`/`referrer` query params entirely (neither source nor referer)', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    // The referer is the real document referrer (HTTP header / explicit forward), not a
    // URL query param. `referer`/`referrer` query params are not configured source
    // aliases either, so they must be dropped completely.
    get('/test?referer=newsletter&referrer='.urlencode('https://news.ycombinator.com'), [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', null, $this->apiUrl);
    assertVisitorTrackedWith('track_referer', null, $this->apiUrl);
});

it('never reads a standard HTTP header into a source, even when aliased in the config', function () {
    config(['simplestats-client.tracking_codes.source' => ['utm_source', 'ref', 'referer']]);
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    // Even with `referer` mis-aliased as a source, the bare HTTP Referer header must not
    // leak into the source. It only feeds the dedicated referer field.
    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'referer' => 'https://news.ycombinator.com',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', null, $this->apiUrl);
    assertVisitorTrackedWith('track_referer', 'news.ycombinator.com', $this->apiUrl);
});

it('keeps a `ref` query param as a campaign source', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    // `ref` stays a source alias: a non-domain value like a campaign tag must remain
    // a source and must not be recorded as a referer.
    get('/test?ref=newsletter', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', 'newsletter', $this->apiUrl);
    assertVisitorTrackedWith('track_referer', null, $this->apiUrl);
});

it('records an external HTTP Referer header as the referer, never as the source', function () {
    Route::get('/dashboard', fn () => true)->middleware(['web', CheckTracking::class]);

    // A real browser sends the Referer header. It must feed the referer field (handled
    // by getReferer()) and never be recorded as a traffic source.
    get('/dashboard', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'referer' => 'https://news.ycombinator.com/item?id=1',
    ])->assertOk();

    assertVisitorTrackedWith('track_referer', 'news.ycombinator.com', $this->apiUrl);
    assertVisitorTrackedWith('track_source', null, $this->apiUrl);
});

it('filters the app own domain sent via the explicit document referer', function () {
    config(['app.url' => 'https://my-app.test']);
    Route::get('/dashboard', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/dashboard?document_referer='.urlencode('https://my-app.test/login'), [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('track_referer', null, $this->apiUrl);
});

it('does not record the app own domain (from the HTTP Referer header) as a source', function () {
    config(['app.url' => 'https://my-app.test']);
    Route::get('/dashboard', fn () => true)->middleware(['web', CheckTracking::class]);

    // Session expired + in-app click: the browser sends the app's own URL as Referer.
    get('/dashboard', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'referer' => 'https://my-app.test/login',
    ])->assertOk();

    assertVisitorTrackedWith('track_source', null, $this->apiUrl);
    assertVisitorTrackedWith('track_referer', null, $this->apiUrl);
});

it('does not treat an unrelated host that merely shares a substring as the own domain', function () {
    config(['app.url' => 'https://your-app.test']);
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    // `app.test` is a substring of `your-app.test` but a different domain; the referer
    // must be tracked, not dropped as the own domain.
    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'referer' => 'https://app.test',
    ])->assertOk();

    assertVisitorTrackedWith('track_referer', 'app.test', $this->apiUrl);
});

it('keeps a subdomain of the app host as a referer (separate property)', function () {
    config(['app.url' => 'https://my-app.test']);
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    // A subdomain like a landing page or docs site is a distinct property; traffic
    // from it to the app is a real referer and must not be dropped as the own domain.
    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'referer' => 'https://account.my-app.test',
    ])->assertOk();

    assertVisitorTrackedWith('track_referer', 'account.my-app.test', $this->apiUrl);
});

it('reads page from X-Page header, overriding the request path', function () {
    Route::get('/track-init', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/track-init', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
        'HTTP_X_PAGE' => '/landing',
    ])->assertOk();

    assertVisitorTrackedWith('page_entry', '/landing', $this->apiUrl);
});

it('reads page from query parameter', function () {
    Route::get('/track-init', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/track-init?page=/pricing', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    assertVisitorTrackedWith('page_entry', '/pricing', $this->apiUrl);
});

it('dispatches trackVisitor only once per day per visitor', function () {
    Route::get('/page', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/page', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    flushDeferred();

    get('/page', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    flushDeferred();

    Http::assertSentCount(1);
});

it('sends resolved visitor properties within the visitor payload', function () {
    config(['simplestats-client.custom_properties_resolvers.visitor' => AbTestPropertiesResolver::class]);
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    flushDeferred();

    Http::assertSent(function ($request) {
        return $request->url() === $this->apiUrl.'stats-visitor'
            && ($request->data()['properties']['ab_test'] ?? null) === 'B';
    });
});

it('sends empty properties when no resolver is configured', function () {
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    flushDeferred();

    Http::assertSent(function ($request) {
        return $request->url() === $this->apiUrl.'stats-visitor'
            && empty($request->data()['properties']);
    });
});

it('ignores a configured resolver that does not implement the contract', function () {
    config(['simplestats-client.custom_properties_resolvers.visitor' => InvalidPropertiesResolver::class]);
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    flushDeferred();

    Http::assertSent(function ($request) {
        return $request->url() === $this->apiUrl.'stats-visitor'
            && empty($request->data()['properties']);
    });
});

it('still tracks the visitor when the properties resolver throws', function () {
    config(['simplestats-client.custom_properties_resolvers.visitor' => ThrowingVisitorPropertiesResolver::class]);
    Route::get('/test', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/test', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])->assertOk();

    flushDeferred();

    Http::assertSent(function ($request) {
        return $request->url() === $this->apiUrl.'stats-visitor'
            && empty($request->data()['properties']);
    });
});

it('falls back to session storage by default', function () {
    config(['simplestats-client.tracking_storage' => 'session']);
    Route::get('/page', fn () => true)->middleware(['web', CheckTracking::class]);

    get('/page?utm_source=newsletter', [
        'REMOTE_ADDR' => '8.8.8.8',
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'navigate',
    ])
        ->assertOk()
        ->assertSessionHas('simplestats.tracking');

    assertVisitorTrackedWith('track_source', 'newsletter', $this->apiUrl);
});
