(function () {
    if (window.__simplestatsBeacon) {
        return;
    }

    window.__simplestatsBeacon = true;

    var script = document.currentScript;
    var endpoint = script && script.src ? script.src.replace(/\.js(\?.*)?$/, '') : __FALLBACK_ENDPOINT__;
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

        // credentials: 'include' lets a site on a sibling subdomain share the visitor's session with the app.
        fetch(endpoint + '?' + params.toString(), {
            mode: 'no-cors',
            credentials: 'include',
            keepalive: true,
        }).catch(function () {});
    }

    // Client-side navigations of SPAs (Next.js, Nuxt, ...) never reach the server on their own.
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
