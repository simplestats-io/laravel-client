<?php

namespace SimpleStatsIo\LaravelClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Jaybizzle\CrawlerDetect\CrawlerDetect;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use SimpleStatsIo\LaravelClient\Visitor;

class CheckTracking
{
    public function __construct(protected CrawlerDetect $crawlerDetect) {}

    public function handle(Request $request, Closure $next)
    {
        $ip = $this->resolveIp($request);

        if (! $this->doTracking($request, $ip)) {
            return $next($request);
        }

        $collectedTrackingData = collect(config('simplestats-client.tracking_codes'))->mapWithKeys(
            function ($params, $key) use ($request) {
                foreach ($params as $param) {
                    if ($value = $request->input($param)) {
                        return [$key => $value];
                    }
                }

                return [$key => null];
            });

        // remove empty/null items...
        $cleanedTrackingData = $collectedTrackingData->filter();

        $userAgent = ($request->userAgent()) ? urlencode($request->userAgent()) : null;
        $referer = $this->getReferer();
        $path = $request->getPathInfo();

        $cleanedTrackingData->put('ip', $ip);
        $cleanedTrackingData->put('referer', (! empty($referer)) ? $referer : null);
        $cleanedTrackingData->put('page', (! empty($path)) ? $path : null);
        $cleanedTrackingData->put('user_agent', $userAgent);

        session()->put(['simplestats.tracking' => $cleanedTrackingData]);

        $this->trackVisitor($ip, $userAgent);

        return $next($request);
    }

    protected function getReferer(): string
    {
        if (empty($_SERVER['HTTP_REFERER'])) {
            return '';
        }

        // referer always without www and make sure referes like http://foo.de, https://foo.de, foo.de and www.foo.de are working
        $referer = Str::replaceFirst('www.', '', parse_url($_SERVER['HTTP_REFERER'])['host']
            ?? parse_url('https://'.$_SERVER['HTTP_REFERER'])['host']
            ?? '');

        // do not track the app url as a own referer, if that happens...
        if (! empty($referer) && ! Str::of(config('app.url'))->contains($referer)) {
            return $referer;
        }

        return '';
    }

    protected function doTracking(Request $request, ?string $ip): bool
    {
        return empty(session()->get('simplestats.tracking'))
            && $request->isMethod('get')
            && ! $this->inExceptArray($request)
            && ! $this->isBlockedIp($ip)
            && is_string($request->userAgent())
            && ! $this->crawlerDetect->isCrawler($request->userAgent());
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

    public function trackVisitor(?string $ip, ?string $userAgent): void
    {
        $visitorTime = SimplestatsClient::getTime(now());
        $visitorHash = SimplestatsClient::createVisitorHash($visitorTime, $ip, $userAgent);

        session()->put('simplestats.visitor_hash', $visitorHash);

        SimplestatsClient::trackVisitor(new Visitor($visitorHash));
    }
}
