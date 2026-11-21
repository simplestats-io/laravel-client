<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Throwable;

/**
 * Remembers the `skip` hints of the SimpleStats API (e.g. a referer it identified as
 * referrer spam), so later visitors with that value cost neither a queue job nor an
 * API call. Unknown types are ignored, the API may add more later.
 *
 * Uses the 'file' store for the same reason as DeviceDetectorCache: the app's default
 * store is often 'database', which would turn this hot-path lookup into a query.
 * Fail-open both ways: a broken store never blocks tracking or the host app.
 */
class SkipRules
{
    public const CACHE_PREFIX = 'simplestats:skip:';

    protected const SUPPORTED_TYPES = ['referer'];

    protected const MAX_TTL = 604800;

    public function __construct(protected string $storeName = 'file') {}

    public function remember(mixed $rules): void
    {
        if (! is_array($rules)) {
            return;
        }

        foreach ($rules as $rule) {
            $type = is_array($rule) ? ($rule['type'] ?? null) : null;
            $value = is_array($rule) ? ($rule['value'] ?? null) : null;
            $ttl = is_array($rule) ? ($rule['ttl'] ?? null) : null;

            if (! in_array($type, self::SUPPORTED_TYPES, true)
                || ! is_string($value) || $value === ''
                || ! is_numeric($ttl) || (int) $ttl <= 0) {
                continue;
            }

            try {
                Cache::store($this->storeName)->put($this->key($type, $value), true, min((int) $ttl, self::MAX_TTL));
            } catch (Throwable) {
                continue;
            }
        }
    }

    public function shouldSkip(string $type, ?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        try {
            $store = Cache::store($this->storeName);

            foreach ($this->candidates($type, Str::lower($value)) as $candidate) {
                if ($store->has($this->key($type, $candidate))) {
                    return true;
                }
            }
        } catch (Throwable) {
            return false;
        }

        return false;
    }

    /**
     * The API sends the flagged domain, a referer may be one of its subdomains.
     *
     * @return list<string>
     */
    protected function candidates(string $type, string $value): array
    {
        if ($type !== 'referer') {
            return [$value];
        }

        $labels = explode('.', $value);
        $candidates = [];

        while (count($labels) >= 2) {
            $candidates[] = implode('.', $labels);
            array_shift($labels);
        }

        return $candidates;
    }

    protected function key(string $type, string $value): string
    {
        return self::CACHE_PREFIX.$type.':'.md5(Str::lower($value));
    }
}
