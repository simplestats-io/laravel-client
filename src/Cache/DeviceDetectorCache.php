<?php

namespace SimpleStatsIo\LaravelClient\Cache;

use DeviceDetector\Cache\CacheInterface;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * device-detector cache adapter bound to a fixed Laravel cache store.
 *
 * Defaults to the 'file' store rather than the app's default store, because the
 * default is often 'database' (Laravel's default since v11), whose value column
 * overflows on the ~1.7 MB regex blob (e.g. MySQL). 'file' is always defined and
 * works everywhere. The adapter shipped with matomo/device-detector
 * (DeviceDetector\Cache\LaravelCache) is hardwired to the default store, so we
 * need our own to target 'file'.
 *
 * Every store operation is fail-open: a failing store (unwritable cache dir, Redis
 * down, ...) must never bubble up into CheckTracking and break the host app's
 * request. On failure the detector falls back to the uncached parse path: slower,
 * but correct and non-breaking.
 */
class DeviceDetectorCache implements CacheInterface
{
    public function __construct(protected string $storeName = 'file') {}

    public function fetch(string $id)
    {
        try {
            return $this->store()->get($id);
        } catch (Throwable) {
            return null;
        }
    }

    public function contains(string $id): bool
    {
        try {
            return $this->store()->has($id);
        } catch (Throwable) {
            return false;
        }
    }

    public function save(string $id, $data, int $lifeTime = 0): bool
    {
        try {
            return $this->store()->put($id, $data, $lifeTime > 0 ? $lifeTime : null);
        } catch (Throwable) {
            return false;
        }
    }

    public function delete(string $id): bool
    {
        try {
            return $this->store()->forget($id);
        } catch (Throwable) {
            return false;
        }
    }

    public function flushAll(): bool
    {
        try {
            return $this->store()->flush();
        } catch (Throwable) {
            return false;
        }
    }

    protected function store(): Repository
    {
        return Cache::store($this->storeName);
    }
}
