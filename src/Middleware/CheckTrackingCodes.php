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

        // remove empty/null elements...
        $filtered = $collectedTrackingData->filter();

        if ($filtered->isNotEmpty()) {
            $request->session()->put(['simplestats.tracking' => $filtered]);
        }

        return $next($request);
    }
}
