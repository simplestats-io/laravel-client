<?php

namespace SimpleStatsIo\LaravelClient\Jobs;

use Illuminate\Http\Client\Response;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\Services\ApiConnector;
use SimpleStatsIo\LaravelClient\Services\PageViewBuffer;
use SimpleStatsIo\LaravelClient\Services\PageViewSettings;

/**
 * One class for both roles: a "direct" job carries a single view from the web request
 * and is merged into the worker buffer when one is active, otherwise it sends itself.
 * A "worker" job is a sealed buffer batch and always sends.
 *
 * The batch id is fixed when the job is built, so every retry and every duplicate
 * delivery of the queue carries the same id and the API books the batch only once.
 */
class SendPageViews extends SendApiRequest
{
    public const MODE_DIRECT = 'direct';

    public const MODE_WORKER = 'worker';

    /**
     * About 22 hours in total. The API keeps batch ids for 72 hours, so a retry can never
     * be booked twice, and views do not trickle into week-old buckets like other events.
     */
    public int $tries = 9;

    public array $backoff = [5, 10, 60, 600, 3600, 3 * 3600, 6 * 3600, 12 * 3600];

    /**
     * @param  list<array{h: string, t: int, n: int, p: string}>  $views
     */
    public function __construct(array $views, ?string $batchId = null, string $mode = self::MODE_DIRECT)
    {
        parent::__construct('stats-views', [
            'batch_id' => $batchId ?? (string) Str::orderedUuid(),
            'views' => $views,
            'meta' => ['mode' => $mode],
        ]);

        $this->queue = app(PageViewSettings::class)->queue();

        // A flush inside a customer job's transaction (or with after_commit queues) would
        // otherwise only be registered as a callback and silently vanish on rollback.
        $this->afterCommit = false;
    }

    public function handle(ApiConnector $apiConnector): void
    {
        $buffer = app(PageViewBuffer::class);

        if ($buffer->isPaused()) {
            return;
        }

        if ($buffer->isActive()) {
            if ($this->mode() === self::MODE_DIRECT) {
                $buffer->merge($this->views());

                return;
            }
        } elseif (! $buffer->isEmpty()) {
            // the worker loop went away (or got cut off) while views were buffered
            $buffer->flush();
        }

        parent::handle($apiConnector);
    }

    public function batchId(): string
    {
        return $this->payload['batch_id'];
    }

    public function mode(): string
    {
        return $this->payload['meta']['mode'];
    }

    /**
     * @return list<array{h: string, t: int, n: int, p: string}>
     */
    public function views(): array
    {
        return $this->payload['views'];
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['simplestats', 'simplestats:views'];
    }

    protected function send(ApiConnector $apiConnector): Response
    {
        // Without a real queue the request runs inside the web process and has no retry,
        // so a slow API must not hold the PHP worker for long.
        $timeout = $this->job instanceof SyncJob ? 2 : null;

        return $apiConnector->request($this->route, $this->payload, $this->method, $timeout);
    }

    protected function succeeded(Response $response): void
    {
        $pause = $response->json('pause');

        if (is_numeric($pause) && (int) $pause > 0) {
            app(PageViewBuffer::class)->pauseFor((int) $pause);
        }
    }
}
