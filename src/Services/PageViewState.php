<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Per visitor-day dedup and cap state. Lives next to the tracking storage (session or
 * cache) but under its own key, so custom TrackingStorage implementations keep working.
 * Only a short hash of the last page is kept, never the path itself.
 */
class PageViewState
{
    public const SESSION_KEY = 'simplestats.page_views';

    public const CACHE_KEY_PREFIX = 'simplestats:page_views:';

    /**
     * Counts the view unless it repeats the last counted page or the cap is reached.
     */
    public function count(Request $request, string $visitorHash, string $day, string $page, int $max): bool
    {
        $useCache = config('simplestats-client.tracking_storage') === 'cache';

        if (! $useCache && ! $request->hasSession()) {
            return false;
        }

        $state = $useCache
            ? Cache::get(self::CACHE_KEY_PREFIX.$visitorHash)
            : $request->session()->get(self::SESSION_KEY);

        if (! is_array($state) || ($state['h'] ?? null) !== $visitorHash || ($state['d'] ?? null) !== $day) {
            $state = ['h' => $visitorHash, 'd' => $day, 'n' => 0, 'p' => null];
        }

        $pageKey = substr(sha1($page), 0, 12);

        if ((int) $state['n'] >= $max || $state['p'] === $pageKey) {
            return false;
        }

        $state['n'] = (int) $state['n'] + 1;
        $state['p'] = $pageKey;

        if ($useCache) {
            Cache::put(self::CACHE_KEY_PREFIX.$visitorHash, $state, now()->endOfDay());
        } else {
            $request->session()->put(self::SESSION_KEY, $state);
        }

        return true;
    }
}
