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

        // credentials: 'include' lets a site on a sibling subdomain share the visitor's
        // session with the app. History hooks report client-side navigations of SPAs
        // (Next.js, Nuxt, ...), which never reach the server on their own.
        $script = <<<JS
            (function () {
                if (window.__simplestatsBeacon) {
                    return;
                }

                window.__simplestatsBeacon = true;

                var script = document.currentScript;
                var endpoint = script && script.src ? script.src.replace(/\.js(\?.*)?$/, '') : {$fallback};
                var lastPath = null;

                function ping() {
                    if (location.pathname === lastPath) {
                        return;
                    }

                    lastPath = location.pathname;

                    var params = new URLSearchParams(location.search);
                    params.set('page', location.pathname);

                    if (document.referrer) {
                        params.set('document_referer', document.referrer);
                    }

                    fetch(endpoint + '?' + params.toString(), {
                        mode: 'no-cors',
                        credentials: 'include',
                        keepalive: true,
                    }).catch(function () {});
                }

                ['pushState', 'replaceState'].forEach(function (method) {
                    var original = history[method];

                    history[method] = function () {
                        var result = original.apply(this, arguments);
                        setTimeout(ping, 0);

                        return result;
                    };
                });

                window.addEventListener('popstate', ping);

                ping();
            })();
            JS;

        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
