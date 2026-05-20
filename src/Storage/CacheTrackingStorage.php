<?php

namespace SimpleStatsIo\LaravelClient\Storage;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CacheTrackingStorage implements TrackingStorage
{
    public const CACHE_KEY_PREFIX = 'simplestats:tracking:';

    public function put(string $identifier, mixed $data): void
    {
        Cache::put(self::CACHE_KEY_PREFIX.$identifier, $data, now()->endOfDay());
    }

    public function get(?string $identifier): Collection
    {
        if (empty($identifier)) {
            return collect();
        }

        return Cache::get(self::CACHE_KEY_PREFIX.$identifier) ?? collect();
    }

    public function has(string $identifier): bool
    {
        return Cache::has(self::CACHE_KEY_PREFIX.$identifier);
    }
}
