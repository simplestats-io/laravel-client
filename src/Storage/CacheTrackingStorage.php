<?php

namespace SimpleStatsIo\LaravelClient\Storage;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class CacheTrackingStorage implements TrackingStorage
{
    public const CACHE_KEY_PREFIX = 'simplestats:tracking:';

    public function put(string $identifier, mixed $data): void
    {
        // Store a plain array so the value survives any cache serializer (e.g. JSON)
        // and reading it back never requires this package's classes.
        Cache::put(self::CACHE_KEY_PREFIX.$identifier, $data instanceof Arrayable ? $data->toArray() : $data, now()->endOfDay());
    }

    public function get(?string $identifier): Collection
    {
        if (empty($identifier)) {
            return collect();
        }

        // Older cache entries may still hold a serialized Collection, so always wrap.
        return collect(Cache::get(self::CACHE_KEY_PREFIX.$identifier) ?? []);
    }

    public function has(string $identifier): bool
    {
        return Cache::has(self::CACHE_KEY_PREFIX.$identifier);
    }
}
