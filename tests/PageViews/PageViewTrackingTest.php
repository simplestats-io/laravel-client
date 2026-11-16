<?php

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use SimpleStatsIo\LaravelClient\Events\VisitorTracked;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use SimpleStatsIo\LaravelClient\Jobs\SendPageViews;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;
use SimpleStatsIo\LaravelClient\Tests\Models\User;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

class CustomPanelAuthenticate extends Authenticate {}

const PV_CHROME = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36';

function pvDocumentHeaders(array $headers = []): array
{
    return [
        'User-Agent' => PV_CHROME,
        'Sec-Fetch-Mode' => 'navigate',
        'Sec-Fetch-Dest' => 'document',
        'REMOTE_ADDR' => '8.8.8.8',
        ...$headers,
    ];
}

function pvFetchHeaders(array $headers = []): array
{
    return [
        'User-Agent' => PV_CHROME,
        'Sec-Fetch-Mode' => 'cors',
        'Sec-Fetch-Dest' => 'empty',
        'REMOTE_ADDR' => '8.8.8.8',
        ...$headers,
    ];
}

/**
 * @return list<array{h: string, t: int, n: int, p: string}>
 */
function pushedPageViews(): array
{
    return Queue::pushed(SendPageViews::class)
        ->flatMap(fn (SendPageViews $job) => $job->views())
        ->values()
        ->all();
}

function pushedPages(): array
{
    return array_column(pushedPageViews(), 'p');
}

beforeEach(function () {
    Http::fake([config('simplestats-client.api_url').'*' => Http::response([], 200)]);
    Queue::fake();

    config([
        'queue.default' => 'database',
        'simplestats-client.tracking_storage' => 'cache',
    ]);

    Route::middleware('web')->group(function () {
        Route::get('/', fn () => 'home');
        Route::get('/pricing', fn () => 'pricing');
        Route::get('/blog/{slug}', fn () => 'post');
        Route::get('/admin/dashboard', fn () => 'admin');
        Route::get('/report.pdf', fn () => 'pdf as html');
        Route::get('/json', fn () => response()->json(['ok' => true]));
        Route::get('/download', fn () => response('file', 200, ['Content-Type' => 'text/html', 'Content-Disposition' => 'attachment; filename="a.html"']));
        Route::get('/missing', fn () => response('nope', 404));
        Route::get('/redirect', fn () => redirect('/pricing'));
        Route::get('/not-modified', fn () => response('', 304));
        Route::get('/inertia', fn () => response()->json(['component' => 'Page'], 200, ['X-Inertia' => 'true']));
        Route::post('/form', fn () => 'posted');
        Route::get('/app', fn () => 'app')->middleware('auth');
        Route::get('/panel', fn () => 'panel')->middleware(CustomPanelAuthenticate::class);
    });
});

it('reports the first qualified view of a visitor and every further page', function () {
    get('/', pvDocumentHeaders())->assertOk();
    get('/pricing', pvDocumentHeaders())->assertOk();

    $views = pushedPageViews();

    expect(array_column($views, 'p'))->toBe(['/', '/pricing'])
        ->and($views[0]['n'])->toBe(1)
        ->and($views[0]['t'] % 60)->toBe(0)
        ->and($views[0]['h'])->toBe($views[1]['h']);
});

it('sends views with the hash of the visitor event', function () {
    $visitorHash = null;

    Event::listen(VisitorTracked::class, function (VisitorTracked $event) use (&$visitorHash) {
        $visitorHash = $event->payload['visitor_hash'];
    });

    get('/', pvDocumentHeaders())->assertOk();

    expect($visitorHash)->not->toBeNull()
        ->and(pushedPageViews()[0]['h'])->toBe($visitorHash);
});

it('counts the same page twice in a row only once, ignoring the query string', function () {
    get('/pricing', pvDocumentHeaders())->assertOk();
    get('/pricing', pvDocumentHeaders())->assertOk();
    get('/pricing?page=2', pvDocumentHeaders())->assertOk();
    get('/', pvDocumentHeaders())->assertOk();
    get('/pricing', pvDocumentHeaders())->assertOk();

    expect(pushedPages())->toBe(['/pricing', '/', '/pricing']);
});

it('stops at 100 views per visitor and day', function () {
    foreach (range(1, 101) as $i) {
        get("/blog/post-{$i}", pvDocumentHeaders())->assertOk();
    }

    expect(pushedPages())->toHaveCount(100)
        ->and(pushedPages())->not->toContain('/blog/post-101');
});

it('normalizes the reported path', function () {
    get('/pricing/', pvDocumentHeaders())->assertOk();
    get('/blog/Hello-World', pvDocumentHeaders())->assertOk();

    expect(pushedPages())->toBe(['/pricing', '/blog/Hello-World']);
});

it('does not count pages with a file extension', function () {
    get('/', pvDocumentHeaders())->assertOk();
    get('/report.pdf', pvDocumentHeaders())->assertOk();

    expect(pushedPages())->toBe(['/']);
});

it('counts navigations of all supported frameworks', function (string $url, array $headers) {
    get('/', pvDocumentHeaders())->assertOk();
    get($url, $headers);

    expect(pushedPages())->toBe(['/', parse_url($url, PHP_URL_PATH)]);
})->with([
    'Inertia visit' => ['/inertia', pvFetchHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])],
    'Livewire wire:navigate' => ['/pricing', pvFetchHeaders(['X-Livewire-Navigate' => '1'])],
    'Turbo Drive visit' => ['/pricing', pvFetchHeaders(['X-Turbo-Request-Id' => 'abc'])],
    'Turbo prefetch' => ['/pricing', pvFetchHeaders(['X-Turbo-Request-Id' => 'abc', 'X-Sec-Purpose' => 'prefetch'])],
    'htmx boosted' => ['/pricing', pvFetchHeaders(['HX-Request' => 'true', 'HX-Boosted' => 'true'])],
    'browser without Sec-Fetch headers' => ['/pricing', ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15', 'Accept' => 'text/html', 'REMOTE_ADDR' => '8.8.8.8']],
    'not modified response' => ['/not-modified', pvDocumentHeaders()],
]);

it('does not count requests that are no page view', function (string $url, array $headers) {
    get('/', pvDocumentHeaders())->assertOk();
    get($url, $headers);

    expect(pushedPages())->toBe(['/']);
})->with([
    'Sec-Purpose prefetch' => ['/pricing', pvDocumentHeaders(['Sec-Purpose' => 'prefetch'])],
    'Sec-Purpose prerender' => ['/pricing', pvDocumentHeaders(['Sec-Purpose' => 'prefetch;prerender'])],
    'Purpose prefetch' => ['/pricing', pvDocumentHeaders(['Purpose' => 'prefetch'])],
    'Inertia prefetch' => ['/inertia', pvFetchHeaders(['X-Inertia' => 'true', 'Purpose' => 'prefetch'])],
    'X-Moz prefetch' => ['/pricing', pvDocumentHeaders(['X-Moz' => 'prefetch'])],
    'Precognition' => ['/pricing', pvFetchHeaders(['Precognition' => 'true'])],
    'Inertia partial reload' => ['/inertia', pvFetchHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Page'])],
    'Inertia visit without Inertia response' => ['/pricing', pvFetchHeaders(['X-Inertia' => 'true'])],
    'Livewire update' => ['/pricing', pvFetchHeaders(['X-Livewire' => 'true'])],
    'Turbo frame' => ['/pricing', pvFetchHeaders(['X-Turbo-Request-Id' => 'abc', 'Turbo-Frame' => 'list'])],
    'htmx partial' => ['/pricing', pvFetchHeaders(['HX-Request' => 'true'])],
    'fetch' => ['/pricing', pvFetchHeaders()],
    'iframe' => ['/pricing', pvDocumentHeaders(['Sec-Fetch-Dest' => 'iframe'])],
    'xhr without Sec-Fetch headers' => ['/pricing', ['User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Safari/605.1.15', 'X-Requested-With' => 'XMLHttpRequest', 'REMOTE_ADDR' => '8.8.8.8']],
    'json response' => ['/json', pvDocumentHeaders()],
    'download' => ['/download', pvDocumentHeaders()],
    'error page' => ['/missing', pvDocumentHeaders()],
    'redirect' => ['/redirect', pvDocumentHeaders()],
]);

it('does not count non-GET requests', function () {
    get('/', pvDocumentHeaders())->assertOk();
    post('/form', [], pvDocumentHeaders());

    expect(pushedPages())->toBe(['/']);
});

it('does not count views of visitors that were never tracked', function () {
    get('/', pvDocumentHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']))->assertOk();
    get('/pricing', pvDocumentHeaders(['User-Agent' => 'Googlebot/2.1 (+http://www.google.com/bot.html)']))->assertOk();

    Queue::assertNotPushed(SendPageViews::class);
});

it('skips routes behind a login middleware but counts logged-in users elsewhere', function () {
    $this->actingAs(new User(['id' => 1]));

    get('/', pvDocumentHeaders())->assertOk();
    get('/app', pvDocumentHeaders())->assertOk();
    get('/panel', pvDocumentHeaders())->assertOk();
    get('/pricing', pvDocumentHeaders())->assertOk();

    expect(pushedPages())->toBe(['/', '/pricing']);
});

it('applies the global except list to views', function () {
    get('/', pvDocumentHeaders())->assertOk();
    get('/admin/dashboard', pvDocumentHeaders())->assertOk();
    get('/pricing', pvDocumentHeaders())->assertOk();

    expect(pushedPages())->toBe(['/', '/pricing']);
});

it('lets subclasses exclude views through doViewTracking', function () {
    $middleware = new class(app('SimpleStatsIo\LaravelClient\Storage\TrackingStorage'), app('SimpleStatsIo\LaravelClient\Services\CustomPropertiesResolver')) extends CheckTracking
    {
        protected function doViewTracking($request, $response): bool
        {
            return $request->path() !== 'pricing';
        }
    };

    app()->instance(CheckTracking::class, $middleware);

    get('/', pvDocumentHeaders())->assertOk();
    get('/pricing', pvDocumentHeaders())->assertOk();

    expect(pushedPages())->toBe(['/']);
});

it('reports a forwarded page from a headless ping', function () {
    get('/json?page=/docs/start', pvFetchHeaders())->assertOk();
    get('/json', pvFetchHeaders(['X-Page' => '/docs/next']))->assertOk();
    get('/json', pvFetchHeaders())->assertOk();

    expect(pushedPages())->toBe(['/docs/start', '/docs/next']);
});

it('queues the view even when an outer middleware turns the response into an error', function () {
    Route::get('/version-mismatch', fn () => 'page')
        ->middleware([ConvertToConflict::class, 'web']);

    get('/version-mismatch', pvDocumentHeaders())->assertStatus(409);

    expect(pushedPages())->toBe(['/version-mismatch']);
});

it('keeps the anchor hash in session mode when the computed hash changes', function () {
    config(['simplestats-client.tracking_storage' => 'session']);

    get('/', pvDocumentHeaders())->assertOk();
    $anchorHash = pushedPageViews()[0]['h'];

    get('/pricing', pvDocumentHeaders(['REMOTE_ADDR' => '1.1.1.1']))->assertOk();

    expect(pushedPages())->toBe(['/', '/pricing'])
        ->and(pushedPageViews()[1]['h'])->toBe($anchorHash)
        ->and(SimplestatsClient::getVisitorHash())->toBe($anchorHash);
});

it('stops counting views of a session anchor from an earlier day', function () {
    config(['simplestats-client.tracking_storage' => 'session']);

    get('/', pvDocumentHeaders())->assertOk();

    $this->travel(1)->days();

    get('/pricing', pvDocumentHeaders())->assertOk();

    expect(pushedPages())->toBe(['/']);
});

it('counts nothing when page views are disabled', function () {
    config(['simplestats-client.page_views.enabled' => false]);

    get('/', pvDocumentHeaders())->assertOk();
    get('/pricing', pvDocumentHeaders())->assertOk();

    Queue::assertNotPushed(SendPageViews::class);
});

it('counts nothing on queues that run inside the web request', function (string $driver) {
    config(['queue.default' => 'inline', 'queue.connections.inline' => ['driver' => $driver]]);

    get('/', pvDocumentHeaders())->assertOk();

    Queue::assertNotPushed(SendPageViews::class);
})->with(['sync', 'deferred', 'background']);

class ConvertToConflict
{
    public function handle($request, Closure $next)
    {
        $next($request);

        return response('', 409);
    }
}

it('reports the embedding page of beacon pings and never the beacon itself', function () {
    config([
        'simplestats-client.beacon.enabled' => true,
        'simplestats-client.beacon.path' => 'assets/v',
    ]);

    require __DIR__.'/../../routes/beacon.php';

    $beacon = fn (?string $referer) => get('/assets/v', pvFetchHeaders([
        'Sec-Fetch-Dest' => 'image',
        'Sec-Fetch-Mode' => 'no-cors',
        ...($referer ? ['Referer' => $referer] : []),
    ]))->assertOk();

    $beacon('http://localhost/cached/one?utm_source=x');
    $beacon('http://localhost/cached/one');
    $beacon('http://localhost/cached/two');
    $beacon('https://foreign.test/cached/three');
    $beacon(null);

    expect(pushedPages())->toBe(['/cached/one', '/cached/two']);
});
