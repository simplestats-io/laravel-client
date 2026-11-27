<?php

namespace SimpleStatsIo\LaravelClient\Middleware;

use Carbon\CarbonInterface;
use Closure;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\Cache\DeviceDetectorCache;
use SimpleStatsIo\LaravelClient\Controllers\BeaconController;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use SimpleStatsIo\LaravelClient\Services\CustomPropertiesResolver;
use SimpleStatsIo\LaravelClient\Services\PagePath;
use SimpleStatsIo\LaravelClient\Services\PageViewQualifier;
use SimpleStatsIo\LaravelClient\Services\PageViewSettings;
use SimpleStatsIo\LaravelClient\Services\PageViewState;
use SimpleStatsIo\LaravelClient\Services\SkipRules;
use SimpleStatsIo\LaravelClient\Storage\CacheTrackingStorage;
use SimpleStatsIo\LaravelClient\Storage\TrackingStorage;
use SimpleStatsIo\LaravelClient\Visitor;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CheckTracking
{
    public const PENDING_PAGE_VIEW = 'simplestats.pending_page_view';

    protected const ANCHOR_HASH = 'anchor_hash';

    protected const ANCHOR_DAY = 'anchor_day';

    public function __construct(
        protected TrackingStorage $trackingStorage,
        protected CustomPropertiesResolver $customPropertiesResolver,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        // Captured once: apps may switch the timezone during the request, and the
        // visitor day must be the same one the hash was built with.
        $requestTime = now();

        $ip = $this->resolveIp($request);
        $userAgent = $request->userAgent();

        $visitorHash = SimplestatsClient::createVisitorHash($requestTime, $ip, $userAgent);

        SimplestatsClient::setVisitorHash($this->getContextVisitorHash($visitorHash));

        $beacon = $this->getBeaconContext($request);

        if (! $this->doTracking($request, $ip, $visitorHash, $beacon)) {
            $response = $next($request);

            $this->observePageView($request, $response, $visitorHash, $beacon, $requestTime);

            return $response;
        }

        if ($this->isSkippedReferer($request)) {
            // Marked as handled but without an anchor, so the visitor's next requests
            // (same hash, no external referer anymore) stay untracked and count no views.
            $this->trackingStorage->put($visitorHash, collect(['skipped' => true]));

            return $next($request);
        }

        $collectedTrackingData = collect(config('simplestats-client.tracking_codes'))->mapWithKeys(
            function ($params, $key) use ($request, $beacon) {
                foreach ($params as $param) {
                    $value = $request->input($param)
                        ?? $request->header('x-'.$param)
                        ?? ($beacon['query'][$param] ?? null);

                    if (! empty($value) && is_scalar($value)) {
                        return [$key => $value];
                    }
                }

                return [$key => null];
            });

        $cleanedTrackingData = $collectedTrackingData->filter();

        $cleanedTrackingData->put('ip', $ip);
        $cleanedTrackingData->put('referer', $this->getReferer($request));
        $cleanedTrackingData->put('page', $this->getPage($request, $beacon));
        $cleanedTrackingData->put('user_agent', $userAgent ? urlencode($userAgent) : null);
        $cleanedTrackingData->put('properties', $this->customPropertiesResolver->forVisitor($request));

        // The hash the visitor event is sent with. In session mode the hash computed per
        // request changes after midnight or an IP change, the API only knows this one.
        $cleanedTrackingData->put(self::ANCHOR_HASH, $visitorHash);
        $cleanedTrackingData->put(self::ANCHOR_DAY, $requestTime->format('Y-m-d'));

        $this->trackingStorage->put($visitorHash, $cleanedTrackingData);

        SimplestatsClient::trackVisitor(new Visitor($visitorHash));

        $response = $next($request);

        // Laravel drops the deferred visitor event for 4xx/5xx responses, so the
        // visitor must not stay marked as tracked either. Otherwise a person landing
        // on an error page would be skipped for the rest of the day.
        if ($response instanceof Response && $response->getStatusCode() >= 400) {
            $this->trackingStorage->forget($visitorHash);
        }

        $this->observePageView($request, $response, $visitorHash, $beacon, $requestTime);

        return $response;
    }

    /**
     * Queued only now, after the response was sent, and regardless of its status: Inertia
     * turns a counted visit into a 409 only after this middleware has seen it.
     *
     * No return type on purpose, so subclasses with an own untyped terminate() stay compatible.
     */
    public function terminate(Request $request, $response)
    {
        $view = $request->attributes->get(self::PENDING_PAGE_VIEW);

        if (! is_array($view)) {
            return;
        }

        $request->attributes->remove(self::PENDING_PAGE_VIEW);

        try {
            SimplestatsClient::trackPageView($view['hash'], $view['page'], $view['time']);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Override to exclude further requests from page views. Only called for requests
     * that already qualify as a view of a tracked visitor.
     */
    protected function doViewTracking(Request $request, Response $response): bool
    {
        return true;
    }

    /**
     * Decided before the response leaves this middleware, because StartSession saves the
     * session right after it, so the dedup state would be lost in terminate().
     *
     * @param  array{path: string|null, query: array<string, mixed>, hotlinked: bool}|null  $beacon
     */
    protected function observePageView(Request $request, mixed $response, string $visitorHash, ?array $beacon, CarbonInterface $requestTime): void
    {
        try {
            $settings = app(PageViewSettings::class);

            if (! $response instanceof Response || ($beacon['hotlinked'] ?? false) || ! $settings->enabled()) {
                return;
            }

            $qualifier = app(PageViewQualifier::class);
            $isPing = $beacon !== null || $this->getForwardedPage($request) !== null;

            if (! $qualifier->qualifies($request, $response, $isPing)) {
                return;
            }

            $page = PagePath::normalize($this->getPage($request, $beacon));

            if ($page === null || ! $qualifier->inScope($request, $page) || ! $this->doViewTracking($request, $response)) {
                return;
            }

            $day = $requestTime->format('Y-m-d');
            $anchorHash = $this->getAnchorHash($visitorHash, $day);

            if ($anchorHash === null
                || ! app(PageViewState::class)->count($request, $anchorHash, $day, $page, PageViewSettings::MAX_PER_VISITOR_DAY)) {
                return;
            }

            $request->attributes->set(self::PENDING_PAGE_VIEW, [
                'hash' => $anchorHash,
                'page' => $page,
                'time' => $requestTime,
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Views only count on the day of the visitor event and always carry its hash, so the
     * API can match them to the visitor. Never tracked visitors (bots, blocked IPs, except
     * routes) have no anchor and therefore no views.
     */
    protected function getAnchorHash(string $visitorHash, string $day): ?string
    {
        $trackingData = $this->trackingStorage->get($visitorHash);
        $anchorHash = $trackingData[self::ANCHOR_HASH] ?? null;

        return is_string($anchorHash) && ($trackingData[self::ANCHOR_DAY] ?? null) === $day
            ? $anchorHash
            : null;
    }

    /**
     * In session mode registrations, events and payments of a visitor should reference
     * the hash its visitor event was sent with, also after midnight or an IP change.
     * The cache storage is keyed by the computed hash, so both are always equal there
     * and the extra cache read on every request is skipped.
     */
    protected function getContextVisitorHash(string $visitorHash): string
    {
        if ($this->trackingStorage instanceof CacheTrackingStorage) {
            return $visitorHash;
        }

        $anchorHash = $this->trackingStorage->get($visitorHash)[self::ANCHOR_HASH] ?? null;

        return is_string($anchorHash) && $anchorHash !== '' ? $anchorHash : $visitorHash;
    }

    protected function getReferer(Request $request): ?string
    {
        $rawReferer = $request->input('document_referer')
            ?? $request->header('X-Document-Referer')
            ?? $request->header('referer')
            ?? '';

        $referer = $this->extractHost($rawReferer);

        if (! empty($referer) && ! $this->isOwnDomain($referer)) {
            return $referer;
        }

        return null;
    }

    /**
     * Reduce a url/host value to its bare, lowercased host without a leading "www.".
     *
     * Kept in sync with HasStatsTracking::normalizeHost() (SimpleStats backend) and
     * TrackingData::extractHost() (php-client); update all three together.
     */
    protected function extractHost(?string $value): string
    {
        if (empty($value)) {
            return '';
        }

        $host = parse_url($value)['host']
            ?? parse_url('https://'.$value)['host']
            ?? '';

        return Str::replaceStart('www.', '', Str::lower($host));
    }

    protected function isOwnDomain(?string $value): bool
    {
        $host = $this->extractHost($value);

        if ($host === '') {
            return false;
        }

        // Only exact hosts count as "own". A subdomain (asdf.my-app.com) may be a
        // separate property (landing page, docs, status) whose traffic to the app is a
        // legitimate referer, so it must not be dropped unless it is listed explicitly.
        return collect([config('app.url'), ...(array) config('simplestats-client.own_domains', [])])
            ->map(fn ($domain) => $this->extractHost(is_string($domain) ? $domain : null))
            ->filter()
            ->contains($host);
    }

    /**
     * @param  array{path: string|null, query: array<string, mixed>, hotlinked: bool}|null  $beacon
     */
    protected function getPage(Request $request, ?array $beacon = null): ?string
    {
        $page = $this->getForwardedPage($request);

        // The beacon endpoint itself is never an entry page. Without a usable
        // referer the page stays unknown, but the visit still counts.
        if ($beacon !== null) {
            return $page ?: $beacon['path'];
        }

        return $page ?: $request->getPathInfo();
    }

    /**
     * `page` is also Laravel's pagination parameter (`?page=2`, `?page[number]=2`),
     * so the query value only counts as a forwarded entry page when it is a path.
     */
    protected function getForwardedPage(Request $request): ?string
    {
        $page = $request->input('page');

        if (is_string($page) && Str::startsWith($page, '/')) {
            return $page;
        }

        return $request->header('X-Page') ?: null;
    }

    /**
     * A statically cached page cannot report its own url, because the html is
     * identical for every visitor. The browser does send the embedding page as
     * the Referer of the beacon request though, and for same-origin requests
     * that referer carries the full url including its query string. So the
     * beacon's referer doubles as the entry page and as the source of the
     * tracking codes, which keeps those working without any JavaScript.
     *
     * @return array{path: string|null, query: array<string, mixed>, hotlinked: bool}|null
     */
    protected function getBeaconContext(Request $request): ?array
    {
        if (! config('simplestats-client.beacon.enabled')) {
            return null;
        }

        $beaconPath = BeaconController::path();

        if ($beaconPath === '' || trim($request->getPathInfo(), '/') !== $beaconPath) {
            return null;
        }

        $referer = (string) $request->header('referer');

        // A missing referer still is a page view, we just cannot tell which page.
        // A foreign one is not: the pixel was embedded in a copy of our html on
        // a site we know nothing about, so that request must not count at all.
        // A cross-site request without one cannot be verified, and counting it
        // would let any site with a no-referrer policy embed the pixel freely.
        if (empty($referer)) {
            return ['path' => null, 'query' => [], 'hotlinked' => $request->header('Sec-Fetch-Site') === 'cross-site'];
        }

        if (! $this->isOwnDomain($referer) || $this->isCookielessCrossSiteSession($request, $referer)) {
            return ['path' => null, 'query' => [], 'hotlinked' => true];
        }

        $parts = parse_url($referer) ?: [];

        parse_str($parts['query'] ?? '', $query);

        return ['path' => $parts['path'] ?? null, 'query' => $query, 'hotlinked' => false];
    }

    /**
     * A site on another registrable domain never sends the app's session cookie, so in
     * session mode every ping would start a fresh session and count as a new visitor.
     * Dropping those undercounts instead, until tracking_storage is switched to cache.
     */
    protected function isCookielessCrossSiteSession(Request $request, string $referer): bool
    {
        return config('simplestats-client.tracking_storage', 'session') !== 'cache'
            && $this->isCrossSite($request, $referer)
            && ! $request->cookies->has((string) config('session.cookie'));
    }

    /**
     * Browsers without Sec-Fetch-Site (Safari before 16.4, older Firefox, some webviews)
     * fall back to comparing hosts. That also treats sibling subdomains as cross-site,
     * which only costs their very first, still cookieless ping in those browsers.
     */
    protected function isCrossSite(Request $request, string $referer): bool
    {
        $site = $request->header('Sec-Fetch-Site');

        if ($site !== null) {
            return $site === 'cross-site';
        }

        return $this->extractHost($referer) !== $this->extractHost($request->getHost());
    }

    /**
     * @param  array{path: string|null, query: array<string, mixed>, hotlinked: bool}|null  $beacon
     */
    protected function doTracking(Request $request, ?string $ip, string $visitorHash, ?array $beacon = null): bool
    {
        return ! $this->trackingStorage->has($visitorHash)
            && ! ($beacon['hotlinked'] ?? false)
            && $request->isMethod('get')
            && ! $this->inExceptArray($request, $this->getPage($request, $beacon))
            && ! $this->isBlockedIp($ip)
            && ! $this->isPrefetchRequest($request)
            && ! $this->isUnforwardedBackgroundRequest($request, $beacon)
            && is_string($request->userAgent())
            && ! $this->isBot($request);
    }

    /**
     * A single page app often calls the API (e.g. "who is logged in?") before the beacon
     * script's first ping arrives. That call would start the visit with the API route as
     * entry page and without the campaign parameters, and the ping would come too late.
     * With the beacon enabled, a browser fetch or XHR therefore only starts a visit when it
     * forwards the page itself. Mobile apps and old browsers send no fetch metadata and
     * keep starting visits as before.
     */
    protected function isUnforwardedBackgroundRequest(Request $request, ?array $beacon): bool
    {
        return $beacon === null
            && config('simplestats-client.beacon.enabled')
            && $request->header('Sec-Fetch-Dest') === 'empty'
            && $this->getForwardedPage($request) === null;
    }

    /**
     * Only requests that passed every doTracking() check pay for the cache lookup,
     * and a request without an external referer skips it entirely.
     */
    protected function isSkippedReferer(Request $request): bool
    {
        return app(SkipRules::class)->shouldSkip('referer', $this->getReferer($request));
    }

    protected function isBot(Request $request): bool
    {
        $userAgent = (string) $request->userAgent();

        $detector = new DeviceDetector($userAgent);
        $detector->discardBotInformation();
        $detector->setCache(new DeviceDetectorCache);
        $detector->parse();

        if ($detector->isBot()) {
            return true;
        }

        // device-detector treats headless browsers as regular browsers, but they are
        // practically always automation, so match the User-Agent token directly.
        if (Str::contains($userAgent, 'headless', ignoreCase: true)) {
            return true;
        }

        if ($this->hasInconsistentBrowserHeaders($request)) {
            return true;
        }

        // Real visitors come from browsers or mobile apps. Treat HTTP libraries
        // (curl, python-requests, Go-http-client, ...), feed readers, media
        // players, and PIM clients (email apps) as bot-like, as they almost
        // always represent automated/scripted traffic in a web analytics context.
        return ! $detector->isBrowser() && ! $detector->isMobileApp();
    }

    /**
     * Chromium sends Sec-Fetch-* headers on every request since v76. A User-Agent
     * claiming a modern Chromium browser without them is almost certainly a
     * scripted client with a faked User-Agent (python/curl pretending to be Chrome).
     * Safari and Firefox are deliberately not checked: their Sec-Fetch support
     * arrived late and is less consistent.
     */
    protected function hasInconsistentBrowserHeaders(Request $request): bool
    {
        if (! preg_match('/Chrome\/(\d+)/', (string) $request->userAgent(), $matches)) {
            return false;
        }

        return (int) $matches[1] >= 80 && ! $request->hasHeader('Sec-Fetch-Mode');
    }

    /**
     * Browsers speculatively prefetch/prerender pages (e.g. Chrome speculation
     * rules) that the visitor may never actually see, so those requests must
     * not count as visits. JS-based trackers skip them implicitly because they
     * only fire on page activation.
     */
    protected function isPrefetchRequest(Request $request): bool
    {
        return Str::contains($request->header('Sec-Purpose', ''), ['prefetch', 'prerender'])
            || $request->header('Purpose') === 'prefetch'
            || $request->header('X-Moz') === 'prefetch';
    }

    protected function resolveIp(Request $request): ?string
    {
        $ip = $this->normalizeIp($request->ip());

        if ($ip !== null && $this->isPublicIp($ip)) {
            return $ip;
        }

        $headers = [
            'CF-Connecting-IP',
            'True-Client-IP',
            'X-Forwarded-For',
            'X-Real-IP',
        ];

        foreach ($headers as $header) {
            $value = $request->header($header);

            if ($value === null) {
                continue;
            }

            // Headers can contain multiple IPs (e.g. X-Forwarded-For: client, proxy1, proxy2)
            // Check all candidates to find the first public IP
            $candidates = explode(',', $value);

            foreach ($candidates as $candidate) {
                $candidate = $this->normalizeIp(trim($candidate));

                if ($candidate !== null && $this->isPublicIp($candidate)) {
                    return $candidate;
                }
            }
        }

        return $ip;
    }

    /**
     * Normalize IPv6-mapped IPv4 addresses (e.g. ::ffff:172.17.0.1) to plain IPv4.
     */
    protected function normalizeIp(?string $ip): ?string
    {
        if ($ip === null) {
            return null;
        }

        // Strip ::ffff: prefix from IPv6-mapped IPv4 addresses
        if (stripos($ip, '::ffff:') === 0) {
            $ipv4 = substr($ip, 7);

            if (filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                return $ipv4;
            }
        }

        return $ip;
    }

    protected function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    protected function isBlockedIp(?string $ip): bool
    {
        if ($ip === null) {
            return false;
        }

        $blockedIps = config('simplestats-client.blocked_ips', []);

        foreach ($blockedIps as $blocked) {
            if (str_contains($blocked, '/')) {
                if ($this->ipInCidrRange($ip, $blocked)) {
                    return true;
                }
            } elseif ($ip === $blocked) {
                return true;
            }
        }

        return false;
    }

    protected function ipInCidrRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = -1 << (32 - (int) $bits);
        $subnetLong &= $mask;

        return ($ipLong & $mask) === $subnetLong;
    }

    /**
     * Beacon and headless requests stand in for the page they forward or are embedded
     * in, so an excluded page must not be counted through them either.
     */
    protected function inExceptArray(Request $request, ?string $page = null): bool
    {
        $pagePath = $page !== null
            ? rawurldecode(trim(Str::before(Str::before($page, '#'), '?'), '/') ?: '/')
            : null;

        foreach (config('simplestats-client.except') as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->is($except) || ($pagePath !== null && Str::is($except, $pagePath))) {
                return true;
            }
        }

        return false;
    }
}
