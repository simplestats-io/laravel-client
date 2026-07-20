<?php

namespace SimpleStatsIo\LaravelClient\Middleware;

use Closure;
use DeviceDetector\DeviceDetector;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\Cache\DeviceDetectorCache;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use SimpleStatsIo\LaravelClient\Services\CustomPropertiesResolver;
use SimpleStatsIo\LaravelClient\Storage\TrackingStorage;
use SimpleStatsIo\LaravelClient\Visitor;

class CheckTracking
{
    public function __construct(
        protected TrackingStorage $trackingStorage,
        protected CustomPropertiesResolver $customPropertiesResolver,
    ) {}

    public function handle(Request $request, Closure $next)
    {
        $ip = $this->resolveIp($request);
        $userAgent = $request->userAgent();

        $visitorHash = SimplestatsClient::createVisitorHash(now(), $ip, $userAgent);

        SimplestatsClient::setVisitorHash($visitorHash);

        if (! $this->doTracking($request, $ip, $visitorHash)) {
            return $next($request);
        }

        $collectedTrackingData = collect(config('simplestats-client.tracking_codes'))->mapWithKeys(
            function ($params, $key) use ($request) {
                foreach ($params as $param) {
                    $value = $request->input($param) ?? $request->header('x-'.$param);

                    if (! empty($value)) {
                        return [$key => $value];
                    }
                }

                return [$key => null];
            });

        $cleanedTrackingData = $collectedTrackingData->filter();

        $cleanedTrackingData->put('ip', $ip);
        $cleanedTrackingData->put('referer', $this->getReferer($request));
        $cleanedTrackingData->put('page', $this->getPage($request));
        $cleanedTrackingData->put('user_agent', $userAgent ? urlencode($userAgent) : null);
        $cleanedTrackingData->put('properties', $this->customPropertiesResolver->forVisitor($request));

        $this->trackingStorage->put($visitorHash, $cleanedTrackingData);

        SimplestatsClient::trackVisitor(new Visitor($visitorHash));

        return $next($request);
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

        return Str::replaceFirst('www.', '', parse_url($value)['host']
            ?? parse_url('https://'.$value)['host']
            ?? '');
    }

    protected function isOwnDomain(?string $value): bool
    {
        $host = $this->extractHost($value);
        $appHost = $this->extractHost(config('app.url'));

        if (empty($host) || empty($appHost)) {
            return false;
        }

        // Only the exact host counts as "own". A subdomain (asdf.my-app.com) may be a
        // separate property (landing page, docs, status) whose traffic to the app is a
        // legitimate referer, so it must not be dropped. www is already normalised away.
        return $host === $appHost;
    }

    protected function getPage(Request $request): string
    {
        return $request->input('page')
            ?: $request->header('X-Page')
            ?: $request->getPathInfo();
    }

    protected function doTracking(Request $request, ?string $ip, string $visitorHash): bool
    {
        return ! $this->trackingStorage->has($visitorHash)
            && $request->isMethod('get')
            && ! $this->inExceptArray($request)
            && ! $this->isBlockedIp($ip)
            && ! $this->isPrefetchRequest($request)
            && is_string($request->userAgent())
            && ! $this->isBot($request);
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

    protected function inExceptArray(Request $request): bool
    {
        foreach (config('simplestats-client.except') as $except) {
            if ($except !== '/') {
                $except = trim($except, '/');
            }

            if ($request->is($except)) {
                return true;
            }
        }

        return false;
    }
}
