<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\Jobs\SendPageViews;
use Throwable;

/**
 * In-memory aggregation of page views inside a long-running queue worker. Bound as a
 * singleton (not scoped), so it survives from job to job within one worker process.
 * Only a running worker loop (Looping event) activates it; everywhere else
 * (sync, --once, queue:listen, Vapor) every view job sends its view directly.
 */
class PageViewBuffer
{
    public const HEARTBEAT_CACHE_KEY = 'simplestats:page_views:worker_seen_at';

    public const PAUSE_CACHE_KEY = 'simplestats:page_views:paused_until';

    /**
     * Another listener on Looping that returns a value cuts our listener off, so a loop
     * that has not been seen for this long no longer counts as running.
     */
    protected const LOOP_STALE_AFTER_SECONDS = 30;

    protected const FOREIGN_JOB_FLUSH_AGE_SECONDS = 5;

    protected const HEARTBEAT_EVERY_SECONDS = 60;

    protected const PAUSE_RECHECK_SECONDS = 60;

    protected const REPORT_EVERY_SECONDS = 300;

    protected const MAX_PENDING_VIEWS = 10000;

    /**
     * Beanstalkd rejects jobs above 64 KB by default, so a sealed batch is split well
     * below that, measured on the serialized, JSON-escaped form it takes in the payload.
     */
    protected const MAX_BATCH_BYTES = 48000;

    /** @var array<string, array{h: string, t: int, n: int, p: string}> */
    protected array $entries = [];

    protected ?float $oldestAt = null;

    protected ?float $lastLoopAt = null;

    /** @var list<array{batch_id: string, views: list<array{h: string, t: int, n: int, p: string}>}> */
    protected array $pending = [];

    protected int $pausedUntil = 0;

    protected ?int $pauseCheckedAt = null;

    protected int $lastHeartbeatAt = 0;

    protected int $lastReportAt = 0;

    public function __construct(
        protected PageViewSettings $settings,
    ) {}

    public function loopSeen(): void
    {
        $this->lastLoopAt = $this->now();

        $this->writeHeartbeat();
    }

    public function workerStopping(): void
    {
        $this->flush();

        $this->lastLoopAt = null;
    }

    public function isActive(): bool
    {
        return $this->lastLoopAt !== null
            && $this->now() - $this->lastLoopAt <= self::LOOP_STALE_AFTER_SECONDS
            && $this->settings->canBuffer();
    }

    /**
     * @param  array<int, mixed>  $views
     */
    public function merge(array $views): void
    {
        if ($this->isPaused()) {
            return;
        }

        foreach ($views as $view) {
            if (! is_array($view) || ! isset($view['h'], $view['t'], $view['n'], $view['p'])) {
                continue;
            }

            $key = $view['h'].'|'.$view['t'].'|'.$view['p'];

            if (isset($this->entries[$key])) {
                $this->entries[$key]['n'] = min(1000, $this->entries[$key]['n'] + (int) $view['n']);
            } else {
                $this->entries[$key] = [
                    'h' => (string) $view['h'],
                    't' => (int) $view['t'],
                    'n' => min(1000, (int) $view['n']),
                    'p' => (string) $view['p'],
                ];
            }
        }

        // Measured with the worker clock from the first merge, not from the view time,
        // otherwise a queue backlog would make every entry due at once.
        if ($this->entries !== []) {
            $this->oldestAt ??= $this->now();
        }
    }

    public function isEmpty(): bool
    {
        return $this->entries === [] && $this->pending === [];
    }

    public function entryCount(): int
    {
        return count($this->entries);
    }

    public function isDue(bool $beforeForeignJob = false): bool
    {
        if ($this->pending !== []) {
            return true;
        }

        if ($this->entries === [] || $this->oldestAt === null) {
            return false;
        }

        if (count($this->entries) >= PageViewSettings::MAX_BATCH_ENTRIES) {
            return true;
        }

        $age = $this->now() - $this->oldestAt;

        return $age >= PageViewSettings::FLUSH_INTERVAL_SECONDS
            || ($beforeForeignJob && $age >= self::FOREIGN_JOB_FLUSH_AGE_SECONDS);
    }

    /**
     * Seals the buffer into batches with a fixed batch id and queues them. A batch whose
     * push fails stays pending with the same id and is pushed again on the next flush.
     */
    public function flush(): void
    {
        if ($this->isPaused()) {
            $this->clear();

            return;
        }

        if ($this->entries !== []) {
            foreach ($this->chunk(array_values($this->entries)) as $views) {
                $this->pending[] = ['batch_id' => (string) Str::orderedUuid(), 'views' => $views];
            }

            $this->entries = [];
            $this->oldestAt = null;
        }

        $this->trimPending();

        while ($this->pending !== []) {
            $batch = $this->pending[0];

            try {
                Bus::dispatch(new SendPageViews($batch['views'], $batch['batch_id'], SendPageViews::MODE_WORKER));
            } catch (Throwable $e) {
                $this->report($e);

                return;
            }

            array_shift($this->pending);
        }
    }

    public function pauseFor(int $seconds): void
    {
        $seconds = max(1, min($seconds, 86400));

        $this->pausedUntil = (int) now()->getTimestamp() + $seconds;
        $this->pauseCheckedAt = (int) now()->getTimestamp();
        $this->clear();

        try {
            Cache::put(self::PAUSE_CACHE_KEY, $this->pausedUntil, $seconds);
        } catch (Throwable) {
            // The pause still holds for this process.
        }
    }

    /**
     * The pause is shared through the cache so other workers stop as well. The cache is
     * read at most once a minute per process, not for every view job.
     */
    public function isPaused(): bool
    {
        $now = (int) now()->getTimestamp();

        if ($this->pausedUntil > $now) {
            return true;
        }

        if ($this->pauseCheckedAt === null || $now - $this->pauseCheckedAt >= self::PAUSE_RECHECK_SECONDS) {
            $this->pauseCheckedAt = $now;

            try {
                $this->pausedUntil = (int) Cache::get(self::PAUSE_CACHE_KEY, 0);
            } catch (Throwable) {
                $this->pausedUntil = 0;
            }
        }

        return $this->pausedUntil > $now;
    }

    /**
     * Code running inside the customer's worker loop must never throw, and a broken
     * setup must not flood the error tracker with one report per job.
     */
    public function report(Throwable $e): void
    {
        $now = (int) now()->getTimestamp();

        if ($now - $this->lastReportAt < self::REPORT_EVERY_SECONDS) {
            return;
        }

        $this->lastReportAt = $now;

        try {
            report($e);
        } catch (Throwable) {
            // nothing left to do
        }
    }

    protected function clear(): void
    {
        $this->entries = [];
        $this->pending = [];
        $this->oldestAt = null;
    }

    /**
     * @param  list<array{h: string, t: int, n: int, p: string}>  $views
     * @return list<list<array{h: string, t: int, n: int, p: string}>>
     */
    protected function chunk(array $views): array
    {
        $chunks = [];
        $current = [];
        $bytes = 0;

        foreach ($views as $view) {
            $size = strlen((string) json_encode(serialize($view)));

            if ($current !== [] && ($bytes + $size > self::MAX_BATCH_BYTES || count($current) >= PageViewSettings::MAX_BATCH_ENTRIES)) {
                $chunks[] = $current;
                $current = [];
                $bytes = 0;
            }

            $current[] = $view;
            $bytes += $size;
        }

        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    protected function trimPending(): void
    {
        $total = array_sum(array_map(fn (array $batch) => count($batch['views']), $this->pending));

        while ($total > self::MAX_PENDING_VIEWS && count($this->pending) > 1) {
            $dropped = array_shift($this->pending);
            $total -= count($dropped['views']);
        }
    }

    protected function writeHeartbeat(): void
    {
        $now = (int) now()->getTimestamp();

        if ($now - $this->lastHeartbeatAt < self::HEARTBEAT_EVERY_SECONDS) {
            return;
        }

        $this->lastHeartbeatAt = $now;

        try {
            Cache::put(self::HEARTBEAT_CACHE_KEY, ['at' => $now], 86400);
        } catch (Throwable $e) {
            $this->report($e);
        }
    }

    protected function now(): float
    {
        return now()->getTimestampMs() / 1000;
    }
}
