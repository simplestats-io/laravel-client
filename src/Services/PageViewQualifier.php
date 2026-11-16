<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides whether a request of an already tracked visitor is a page view, based on
 * the request headers and the final response only. Visitor identity, dedup and the
 * daily cap are not part of it (see PageViewState).
 */
class PageViewQualifier
{
    protected const DOCUMENT = 'document';

    protected const INERTIA = 'inertia';

    public function __construct(
        protected Router $router,
    ) {}

    /**
     * An explicit ping (beacon request or a forwarded page) reports a view of another
     * page, so its own response (pixel, JSON, 204) says nothing and is not inspected.
     */
    public function qualifies(Request $request, Response $response, bool $isPing): bool
    {
        if (! $request->isMethod('GET') || $this->isSpeculative($request)) {
            return false;
        }

        $status = $response->getStatusCode();

        if ($isPing) {
            return $status < 400;
        }

        $kind = $this->classify($request);

        if ($kind === null) {
            return false;
        }

        // The browser shows its cached copy, and a 304 carries no Content-Type.
        if ($status === 304) {
            return true;
        }

        if ($status < 200 || $status > 299 || $status === 204 || $status === 205) {
            return false;
        }

        if (Str::contains(Str::lower((string) $response->headers->get('Content-Disposition', '')), 'attachment')) {
            return false;
        }

        if ($kind === self::INERTIA) {
            return $response->headers->get('X-Inertia') === 'true';
        }

        return Str::startsWith(Str::lower((string) $response->headers->get('Content-Type', '')), 'text/html');
    }

    /**
     * Login routes are app usage, not page views, and never count.
     */
    public function inScope(Request $request, string $page): bool
    {
        $path = rawurldecode(trim($page, '/') ?: '/');

        return ! $this->matchesAny($path, (array) config('simplestats-client.except', []))
            && ! $this->isAuthenticatedRoute($request);
    }

    /**
     * Browser speculation and Inertia prefetches never count: Inertia also prefetches on
     * mount, without any user action. Turbo marks its hover prefetch with X-Sec-Purpose,
     * which is deliberately not checked here, because in a Turbo 8 app nearly every mouse
     * navigation is such a prefetch and ignoring it would leave desktop visitors at one view.
     */
    protected function isSpeculative(Request $request): bool
    {
        return Str::contains((string) $request->header('Sec-Purpose', ''), ['prefetch', 'prerender'])
            || $request->header('Purpose') === 'prefetch'
            || $request->header('X-Moz') === 'prefetch'
            || $request->hasHeader('Precognition');
    }

    protected function classify(Request $request): ?string
    {
        if ($request->hasHeader('X-Livewire')) {
            return null;
        }

        if ($request->hasHeader('X-Inertia')) {
            // partial reloads, deferred props, WhenVisible and infinite scroll
            return $request->hasHeader('X-Inertia-Partial-Component') ? null : self::INERTIA;
        }

        if ($request->hasHeader('X-Livewire-Navigate')) {
            return self::DOCUMENT;
        }

        if ($request->hasHeader('Turbo-Frame')) {
            return null;
        }

        if ($request->hasHeader('X-Turbo-Request-Id')) {
            return self::DOCUMENT;
        }

        if ($request->hasHeader('HX-Request')) {
            return $request->hasHeader('HX-Boosted') ? self::DOCUMENT : null;
        }

        if ($request->hasHeader('Sec-Fetch-Dest')) {
            return $request->header('Sec-Fetch-Dest') === 'document' ? self::DOCUMENT : null;
        }

        // No Sec-Fetch headers: plain http, old browsers
        if ($request->header('X-Requested-With') === 'XMLHttpRequest'
            || ! Str::contains((string) $request->header('Accept', ''), 'text/html')) {
            return null;
        }

        return self::DOCUMENT;
    }

    /**
     * Resolved from the route middleware (aliases and groups expanded) instead of
     * auth()->check(), which would log in remember-cookie users on public pages and
     * fire a Login event.
     */
    protected function isAuthenticatedRoute(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        foreach ($this->router->gatherRouteMiddleware($route) as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            $class = Str::before($middleware, ':');

            if (is_a($class, Authenticate::class, true) || is_a($class, AuthenticateWithBasicAuth::class, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $patterns
     */
    protected function matchesAny(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            if ($pattern !== '/') {
                $pattern = trim($pattern, '/');
            }

            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
