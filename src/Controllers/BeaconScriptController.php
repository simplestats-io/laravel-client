<?php

namespace SimpleStatsIo\LaravelClient\Controllers;

use Illuminate\Http\Response;

class BeaconScriptController
{
    public function __invoke(): Response
    {
        // Not url(): it follows the Host / X-Forwarded-Host header, and this response is
        // publicly cacheable, so one forged request could point every visitor's pings at a
        // foreign host. The browser knows where it loaded the script from.
        $fallback = json_encode(rtrim((string) config('app.url'), '/').'/'.BeaconController::path(), JSON_UNESCAPED_SLASHES);

        $script = str_replace('__FALLBACK_ENDPOINT__', $fallback, (string) file_get_contents(__DIR__.'/../../resources/js/beacon.js'));

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
