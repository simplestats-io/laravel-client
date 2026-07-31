<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->apiUrl = config('simplestats-client.api_url');

    Http::fake([
        $this->apiUrl.'*' => Http::response([], 200),
    ]);

    config([
        'simplestats-client.api_token' => 'foo',
        'simplestats-client.tracking_storage' => 'cache',
        'simplestats-client.beacon.enabled' => true,
        'simplestats-client.beacon.path' => 'assets/v',
    ]);
});

/**
 * The package registers the beacon route while booting, which happens before a
 * test can change the config, so the route file is evaluated again here.
 */
function registerBeaconRoute(): void
{
    require __DIR__.'/../../routes/beacon.php';
}

function beaconHeaders(array $extra = []): array
{
    return [
        'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36',
        'sec_fetch_mode' => 'no-cors',
        ...$extra,
    ];
}

it('does not register the beacon route when disabled', function () {
    config(['simplestats-client.beacon.enabled' => false]);

    registerBeaconRoute();

    get('/assets/v', beaconHeaders())->assertNotFound();
});

it('does not register the beacon route without a path', function () {
    config(['simplestats-client.beacon.path' => '']);

    registerBeaconRoute();

    get('/assets/v', beaconHeaders())->assertNotFound();
});

it('registers the beacon in the first configured middleware group', function ($groups, $expected) {
    config(['simplestats-client.middleware_groups' => $groups]);

    registerBeaconRoute();

    $route = collect(Route::getRoutes())->first(fn ($route) => $route->uri() === 'assets/v');

    expect($route->middleware())->toContain($expected);
})->with([
    'web' => [['web'], 'web'],
    'api' => [['api'], 'api'],
    'hybrid picks the first' => [['web', 'api'], 'web'],
]);

it('stays inactive for installs whose published config predates the beacon', function () {
    config(['simplestats-client.beacon' => null]);

    registerBeaconRoute();

    get('/assets/v', beaconHeaders())->assertNotFound();
});

it('keeps tracking normally when the beacon config is missing', function () {
    config(['simplestats-client.beacon' => null]);

    Route::get('/test', fn () => true)->middleware('web');

    get('/test', beaconHeaders(['sec_fetch_mode' => 'navigate']))->assertOk();

    assertVisitorTrackedWith('page_entry', '/test');
});

it('does not track bot requests to the beacon', function () {
    registerBeaconRoute();

    get('/assets/v', [
        'user_agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)',
        'referer' => 'http://localhost/blog/my-post',
    ])->assertOk();

    flushDeferred();

    Http::assertNothingSent();
});

it('responds with an uncacheable pixel', function () {
    registerBeaconRoute();

    $response = get('/assets/v', beaconHeaders())->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('image/gif')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->getContent())->toStartWith('GIF89a');
});

it('derives the entry page from the referer of a beacon request', function () {
    registerBeaconRoute();

    get('/assets/v', beaconHeaders(['referer' => 'http://localhost/blog/my-post']))->assertOk();

    assertVisitorTrackedWith('page_entry', '/blog/my-post');
});

it('derives tracking codes from the referer query of a beacon request', function ($field, $expected) {
    registerBeaconRoute();

    get('/assets/v', beaconHeaders([
        'referer' => 'http://localhost/pricing?utm_source=newsletter&utm_medium=email&utm_campaign=spring&utm_term=seo&utm_content=hero',
    ]))->assertOk();

    assertVisitorTrackedWith($field, $expected);
})->with([
    'source' => ['track_source', 'newsletter'],
    'medium' => ['track_medium', 'email'],
    'campaign' => ['track_campaign', 'spring'],
    'term' => ['track_term', 'seo'],
    'content' => ['track_content', 'hero'],
]);

it('prefers explicit params over the referer on a beacon request', function () {
    registerBeaconRoute();

    get('/assets/v?page=/landing&utm_source=twitter', beaconHeaders([
        'referer' => 'http://localhost/pricing?utm_source=newsletter',
    ]))->assertOk();

    assertVisitorTrackedWith('page_entry', '/landing');
    assertVisitorTrackedWith('track_source', 'twitter');
});

it('reads the external referrer from document_referer on a beacon request', function () {
    registerBeaconRoute();

    get('/assets/v?document_referer=https://news.test/article', beaconHeaders([
        'referer' => 'http://localhost/blog/my-post',
    ]))->assertOk();

    assertVisitorTrackedWith('track_referer', 'news.test');
    assertVisitorTrackedWith('page_entry', '/blog/my-post');
});

it('does not track a beacon hotlinked from a foreign domain', function () {
    registerBeaconRoute();

    get('/assets/v', beaconHeaders(['referer' => 'https://hotlink.test/stolen?utm_source=spam']))->assertOk();

    flushDeferred();

    Http::assertNothingSent();
});

it('ignores array values injected through the referer', function () {
    registerBeaconRoute();

    get('/assets/v', beaconHeaders(['referer' => 'http://localhost/pricing?utm_source[]=array']))->assertOk();

    assertVisitorTrackedWith('track_source', null);
});

it('never records the beacon path as entry page', function () {
    registerBeaconRoute();

    get('/assets/v', beaconHeaders())->assertOk();

    assertVisitorTrackedWith('page_entry', null);
});

it('tracks a beacon visitor only once', function () {
    registerBeaconRoute();

    get('/assets/v', beaconHeaders(['referer' => 'http://localhost/first']))->assertOk();
    get('/assets/v', beaconHeaders(['referer' => 'http://localhost/second']))->assertOk();

    flushDeferred();

    Http::assertSentCount(1);
    assertVisitorTrackedWith('page_entry', '/first');
});
