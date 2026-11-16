<?php

namespace SimpleStatsIo\LaravelClient\Services;

class PageViewSettings
{
    public const MAX_BATCH_ENTRIES = 1000;

    public const FLUSH_INTERVAL_SECONDS = 60;

    public const MAX_PER_VISITOR_DAY = 100;

    /**
     * These drivers run the job inside the dispatching process without retries, so every
     * view would cost an HTTP call in the web request (sync, deferred) or a PHP process
     * (background). Page views stay off on them.
     */
    protected const SYNC_LIKE_DRIVERS = ['sync', 'deferred', 'background'];

    public function enabled(): bool
    {
        return $this->configuredEnabled() && ! $this->runsOnSyncQueue();
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

    /**
     * Without pcntl a worker never sees a graceful stop, so buffered views would be lost
     * on every deploy.
     */
    public function canBuffer(): bool
    {
        return extension_loaded('pcntl');
    }
}
