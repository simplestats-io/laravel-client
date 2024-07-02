<?php

namespace SimpleStatsIo\LaravelClient\Middleware;

use Closure;
use Illuminate\Support\Str;

class CheckTrackingCodes
{
    public function handle($request, Closure $next)
    {
        $collectedTrackingData = collect(config('simplestats-client.tracking_codes'))->mapWithKeys(function ($params, $key) use ($request) {
            foreach ($params as $param) {
                if ($value = $request->input($param)) {
                    return [$key => $value];
                }
            }

            return [$key => null];
        });

        // remove empty/null elements...
        $filtered = $collectedTrackingData->filter();

        if ($referer = $this->getInitialReferer()) {
            $filtered->put('referer', $referer);
        }

        if ($filtered->isNotEmpty()) {
            $request->session()->put(['simplestats.tracking' => $filtered]);
        }

        return $next($request);
    }

    private function getInitialReferer()
    {
        if (isset($_SERVER['HTTP_REFERER']) && ! Str::of($_SERVER['HTTP_REFERER'])->contains(config('app.url'))) {
            return parse_url($_SERVER['HTTP_REFERER'])['host'];
        }

        return '';
    }
}
