<?php

namespace SimpleStatsIo\LaravelClient\Services;

class PageViewSettings
{
    public const MAX_BATCH_ENTRIES = 1000;

    /**
     * These drivers run the job inside the dispatching process without retries, so every
     * view would cost an HTTP call in the web request (sync, deferred) or a PHP process
     * (background). Page views on them need the explicit "sync" opt-in.
     */
    protected const SYNC_LIKE_DRIVERS = ['sync', 'deferred', 'background'];

    public function enabled(): bool
    {
        if (! config('simplestats-client.enabled') || ! config('simplestats-client.page_views.enabled')) {
            return false;
        }

        return ! $this->runsOnSyncQueue() || (bool) config('simplestats-client.page_views.sync');
    }

    public function configuredEnabled(): bool
    {
        return (bool) config('simplestats-client.enabled') && (bool) config('simplestats-client.page_views.enabled');
    }

    public function runsOnSyncQueue(): bool
    {
        return in_array($this->queueDriver(), self::SYNC_LIKE_DRIVERS, true);
    }

    public function queueConnection(): ?string
    {
        $connection = config('queue.default');

        return is_string($connection) ? $connection : null;
    }

    public function queueDriver(): ?string
    {
        $connection = $this->queueConnection();
        $driver = $connection === null ? null : config("queue.connections.{$connection}.driver");

        return is_string($driver) ? $driver : null;
    }

    public function queue(): ?string
    {
        return config('simplestats-client.page_views.queue') ?: config('simplestats-client.queue');
    }

    public function bufferAllowed(): bool
    {
        return config('simplestats-client.page_views.buffer', 'auto') !== 'none'
            && extension_loaded('pcntl');
    }

    public function flushInterval(): int
    {
        return max(5, min(300, (int) config('simplestats-client.page_views.flush_interval', 60)));
    }

    public function maxPerVisitorDay(): int
    {
        return max(1, (int) config('simplestats-client.page_views.max_per_visitor_day', 100));
    }

    public function countsAuthenticatedRoutes(): bool
    {
        return (bool) config('simplestats-client.page_views.count_authenticated_routes', false);
    }

    /**
     * @return array<int, string>
     */
    public function only(): array
    {
        return (array) config('simplestats-client.page_views.only', []);
    }

    /**
     * @return array<int, string>
     */
    public function except(): array
    {
        return (array) config('simplestats-client.page_views.except', []);
    }
}
