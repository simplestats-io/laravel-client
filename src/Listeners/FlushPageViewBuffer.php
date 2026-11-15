<?php

namespace SimpleStatsIo\LaravelClient\Listeners;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\SyncJob;
use SimpleStatsIo\LaravelClient\Jobs\SendPageViews;
use SimpleStatsIo\LaravelClient\Services\PageViewBuffer;
use Throwable;

/**
 * Runs inside the customer's queue worker loop. Every handler returns nothing and
 * swallows every error: returning false from a Looping listener pauses the worker,
 * and an exception would break it.
 */
class FlushPageViewBuffer
{
    public function __construct(
        protected PageViewBuffer $buffer,
    ) {}

    public function onLooping(Looping $event): void
    {
        try {
            $this->buffer->loopSeen();

            if ($this->buffer->isDue()) {
                $this->buffer->flush();
            }
        } catch (Throwable $e) {
            $this->buffer->report($e);
        }
    }

    /**
     * Flushes before a foreign job, so views do not wait behind a long running job.
     * Jobs run synchronously from within another job are skipped, they share that
     * job's transaction.
     */
    public function onJobProcessing(JobProcessing $event): void
    {
        try {
            if ($this->buffer->isEmpty()
                || $event->job instanceof SyncJob
                || $event->connectionName === 'sync'
                || $event->job->resolveName() === SendPageViews::class) {
                return;
            }

            if ($this->buffer->isDue(beforeForeignJob: true)) {
                $this->buffer->flush();
            }
        } catch (Throwable $e) {
            $this->buffer->report($e);
        }
    }

    public function onWorkerStopping(WorkerStopping $event): void
    {
        try {
            $this->buffer->workerStopping();
        } catch (Throwable $e) {
            $this->buffer->report($e);
        }
    }
}
