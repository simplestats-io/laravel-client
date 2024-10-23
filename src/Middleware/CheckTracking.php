<?php

namespace SimpleStatsIo\LaravelClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use hisorange\BrowserDetect\Facade as Browser;

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

        $cleanedTrackingData->put('referer', $this->getReferer());
        $cleanedTrackingData->put('user_agent', $request->userAgent());
        $cleanedTrackingData->put('page', $request->getPathInfo());
        $cleanedTrackingData->put('ip', $request->ip());

        $request->session()->put(['simplestats.tracking' => $cleanedTrackingData]);

        SimplestatsClient::trackVisitor();

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
        return empty($request->session()->get('simplestats.tracking'))
            && $request->isMethod('get')
            && ! $this->inExceptArray($request)
            && ! Browser::parse(urldecode($request->user_agent))->isBot();
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
