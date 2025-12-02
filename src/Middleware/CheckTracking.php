<?php

namespace SimpleStatsIo\LaravelClient\Middleware;

use Closure;
use hisorange\BrowserDetect\Facade as Browser;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use SimpleStatsIo\LaravelClient\Visitor;

class CheckTracking
{
    public function handle(Request $request, Closure $next)
    {
        if (! $this->doTracking($request)) {
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

        $ip = $request->ip() ?? null;
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

    protected function doTracking(Request $request): bool
    {
        return empty(session()->get('simplestats.tracking'))
            && $request->isMethod('get')
            && ! $this->inExceptArray($request)
            && ! $this->isBlockedIp($request->ip())
            && is_string($request->userAgent())
            && ! Browser::parse($request->userAgent())->isBot();
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
