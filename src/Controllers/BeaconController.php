<?php

namespace SimpleStatsIo\LaravelClient\Controllers;

use Illuminate\Http\Response;

class BeaconController
{
    protected const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public static function path(): string
    {
        return trim((string) config('simplestats-client.beacon.path'), '/');
    }

    public function __invoke(): Response
    {
        // The tracking already happened in the CheckTracking middleware, so this
        // only has to be uncacheable and loadable by an <img> tag.
        return response(base64_decode(self::PIXEL), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store',
        ]);
    }
}
