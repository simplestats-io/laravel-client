<?php

namespace LaracraftTech\SimplestatsClient\Middleware;

use Closure;

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

        $sourceKey = $collectedTrackingData->keys()->first();

        // remove empty/null elements...
        $filtered = $collectedTrackingData->filter();

        // fallback source will be the referer if it's available...
        if (! $filtered->has($sourceKey) && $referer = $this->getInitialReferer()) {
            $filtered->put($sourceKey, $referer);
        }

        if ($filtered->isNotEmpty()) {
            $request->session()->put(['simplestats.tracking' => $filtered]);
        }

        return $next($request);
    }

    private function getInitialReferer()
    {
        if (isset($_SERVER['HTTP_REFERER']) && ! str($_SERVER['HTTP_REFERER'])->contains(config('app.url'))) {
            return parse_url($_SERVER['HTTP_REFERER'])['host'];
        }

        return '';
    }
}
