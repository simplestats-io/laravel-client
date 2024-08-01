<?php

namespace SimpleStatsIo\LaravelClient\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class CheckTracking
{
    public function handle(Request $request, Closure $next)
    {
        if (! empty($request->session()->get('simplestats.tracking')) && ! $request->isMethod('get')) {
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

        $cleanedTrackingData->put('referer', $this->getInitialReferer());
        $cleanedTrackingData->put('user_agent', $request->userAgent());
        $cleanedTrackingData->put('page', $request->getPathInfo());
        $cleanedTrackingData->put('ip', $request->ip());

        $request->session()->put(['simplestats.tracking' => $cleanedTrackingData]);

        SimplestatsClient::trackVisitor();

        return $next($request);
    }

    private function getInitialReferer(): string
    {
        if (isset($_SERVER['HTTP_REFERER']) && ! Str::of($_SERVER['HTTP_REFERER'])->contains(config('app.url'))) {

            return parse_url($_SERVER['HTTP_REFERER'])['host']
                ?? parse_url('https://'.$_SERVER['HTTP_REFERER'])['host']
                ?? '';
        }

        return '';
    }
}
