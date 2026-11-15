<?php

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use SimpleStatsIo\LaravelClient\Jobs\SendPageViews;
use SimpleStatsIo\LaravelClient\Listeners\FlushPageViewBuffer;
use SimpleStatsIo\LaravelClient\Services\ApiConnector;
use SimpleStatsIo\LaravelClient\Services\PageViewBuffer;

function pvView(string $page = '/pricing', string $hash = 'visitor-a', int $time = 1794687300): array
{
    return ['h' => $hash, 't' => $time, 'n' => 1, 'p' => $page];
}

function runViewJob(SendPageViews $job): void
{
    $job->handle(app(ApiConnector::class));
}

function flusher(): FlushPageViewBuffer
{
    return app(FlushPageViewBuffer::class);
}

function loop(): void
{
    flusher()->onLooping(new Looping('database', 'default'));
}

function foreignJob(string $name = 'App\\Jobs\\ProcessPodcast'): JobProcessing
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($name);

    return new JobProcessing('database', $job);
}

/**
 * @return list<SendPageViews>
 */
function flushedBatches(): array
{
    return Queue::pushed(SendPageViews::class)->values()->all();
}

function mockedQueueJob(SendPageViews $job, int $attempts): SendPageViews
{
    $queueJob = Mockery::mock(SyncJob::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempts);
    $queueJob->shouldReceive('release')->withAnyArgs();
    $queueJob->shouldReceive('delete');
    $queueJob->shouldReceive('isReleased')->andReturn(false);
    $queueJob->shouldReceive('isDeleted')->andReturn(false);
    $queueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);

    $job->setJob($queueJob);

    return $job;
}

beforeEach(function () {
    $this->apiUrl = config('simplestats-client.api_url');

    $this->apiStatus = 200;
    $this->apiBody = ['accepted' => 1];

    Http::fake(fn () => Http::response($this->apiBody, $this->apiStatus));
    Queue::fake();

    config(['queue.default' => 'database']);

    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('Buffering needs the pcntl extension.');
    }
});

it('sends a view directly when no worker loop runs', function () {
    runViewJob(new SendPageViews([pvView()]));

    Http::assertSent(function ($request) {
        return $request->url() === $this->apiUrl.'stats-views'
            && Str::isUuid($request['batch_id'])
            && $request['views'] === [pvView()]
            && $request['meta'] === ['mode' => 'direct'];
    });

    expect(app(PageViewBuffer::class)->isEmpty())->toBeTrue();
});

it('merges views into the buffer inside a worker loop and aggregates them', function () {
    loop();

    runViewJob(new SendPageViews([pvView('/pricing')]));
    runViewJob(new SendPageViews([pvView('/pricing')]));
    runViewJob(new SendPageViews([pvView('/docs')]));
    runViewJob(new SendPageViews([pvView('/pricing', time: 1794687360)]));

    Http::assertNothingSent();

    expect(app(PageViewBuffer::class)->entryCount())->toBe(3);
});

it('flushes the buffer once the oldest entry reaches the flush interval', function () {
    loop();
    runViewJob(new SendPageViews([pvView()]));
    runViewJob(new SendPageViews([pvView()]));

    $this->travel(30)->seconds();
    loop();

    expect(flushedBatches())->toBeEmpty();

    $this->travel(31)->seconds();
    loop();

    $batches = flushedBatches();

    expect($batches)->toHaveCount(1)
        ->and($batches[0]->mode())->toBe(SendPageViews::MODE_WORKER)
        ->and($batches[0]->views())->toBe([[...pvView(), 'n' => 2]])
        ->and(Str::isUuid($batches[0]->batchId()))->toBeTrue()
        ->and(app(PageViewBuffer::class)->isEmpty())->toBeTrue();
});

it('flushes early once the buffer holds 1000 entries', function () {
    loop();

    foreach (range(1, 1000) as $i) {
        runViewJob(new SendPageViews([pvView("/page-{$i}")]));
    }

    loop();

    expect(collect(flushedBatches())->sum(fn (SendPageViews $job) => count($job->views())))->toBe(1000);
});

it('flushes before a foreign job once the oldest entry is 5 seconds old', function () {
    loop();
    runViewJob(new SendPageViews([pvView()]));

    flusher()->onJobProcessing(foreignJob());
    expect(flushedBatches())->toBeEmpty();

    $this->travel(5)->seconds();

    flusher()->onJobProcessing(foreignJob(SendPageViews::class));
    expect(flushedBatches())->toBeEmpty();

    flusher()->onJobProcessing(foreignJob());
    expect(flushedBatches())->toHaveCount(1);
});

it('flushes when the worker stops and stops buffering afterwards', function () {
    loop();
    runViewJob(new SendPageViews([pvView()]));

    flusher()->onWorkerStopping(new WorkerStopping);

    expect(flushedBatches())->toHaveCount(1)
        ->and(app(PageViewBuffer::class)->isActive())->toBeFalse();

    runViewJob(new SendPageViews([pvView('/docs')]));

    Http::assertSentCount(1);
});

it('sends buffered leftovers once the worker loop went stale', function () {
    loop();
    runViewJob(new SendPageViews([pvView()]));

    $this->travel(31)->seconds();

    runViewJob(new SendPageViews([pvView('/docs')]));

    expect(flushedBatches())->toHaveCount(1)
        ->and(flushedBatches()[0]->views())->toBe([pvView()]);

    Http::assertSent(fn ($request) => $request['views'] === [pvView('/docs')]);
});

it('splits sealed batches below the queue payload limit', function () {
    loop();

    foreach (range(1, 999) as $i) {
        runViewJob(new SendPageViews([pvView('/'.str_pad((string) $i, 250, 'x'))]));
    }

    flusher()->onWorkerStopping(new WorkerStopping);

    $batches = flushedBatches();

    expect(count($batches))->toBeGreaterThan(1)
        ->and(collect($batches)->sum(fn (SendPageViews $job) => count($job->views())))->toBe(999)
        ->and(collect($batches)->map(fn (SendPageViews $job) => $job->batchId())->unique())->toHaveCount(count($batches));

    foreach ($batches as $batch) {
        expect(strlen(json_encode(serialize($batch))))->toBeLessThan(60 * 1024);
    }
});

it('keeps the batch id across retries and uses its own retry ladder', function () {
    $this->apiStatus = 503;

    $job = new SendPageViews([pvView()], mode: SendPageViews::MODE_WORKER);

    runViewJob(mockedQueueJob($job, 1));
    $job->job->shouldHaveReceived('release')->with(5)->once();

    runViewJob(mockedQueueJob($job, 2));
    $job->job->shouldHaveReceived('release')->with(10)->once();

    runViewJob(mockedQueueJob($job, 9));
    $job->job->shouldHaveReceived('delete')->once();

    $batchIds = Http::recorded()->map(fn ($pair) => $pair[0]['batch_id'])->unique();

    expect($batchIds)->toHaveCount(1)
        ->and($batchIds->first())->toBe($job->batchId())
        ->and(array_sum($job->backoff))->toBe(79875);
});

it('drops a batch the API rejects', function () {
    $this->apiStatus = 422;

    $job = mockedQueueJob(new SendPageViews([pvView()]), 1);

    runViewJob($job);

    $job->job->shouldHaveReceived('delete')->once();
    $job->job->shouldNotHaveReceived('release');
});

it('pauses page views when the API asks for it', function () {
    $this->apiBody = ['message' => 'Monthly event limit reached.', 'pause' => 3600];

    runViewJob(new SendPageViews([pvView()]));

    expect(app(PageViewBuffer::class)->isPaused())->toBeTrue()
        ->and(Cache::get(PageViewBuffer::PAUSE_CACHE_KEY))->toBeGreaterThan(now()->getTimestamp());

    runViewJob(new SendPageViews([pvView('/docs')]));

    loop();
    runViewJob(new SendPageViews([pvView('/docs')]));
    flusher()->onWorkerStopping(new WorkerStopping);

    Http::assertSentCount(1);
    expect(flushedBatches())->toBeEmpty();

    $this->travel(3601)->seconds();
    $this->apiBody = ['accepted' => 1];

    runViewJob(new SendPageViews([pvView('/later')]));

    Http::assertSentCount(2);
});

it('picks up a pause set by another worker process', function () {
    Cache::put(PageViewBuffer::PAUSE_CACHE_KEY, now()->getTimestamp() + 600, 600);

    runViewJob(new SendPageViews([pvView()]));

    Http::assertNothingSent();
});

it('never lets an error escape into the worker loop', function () {
    $buffer = Mockery::mock(PageViewBuffer::class);
    $buffer->shouldReceive('loopSeen')->andThrow(new RuntimeException('boom'));
    $buffer->shouldReceive('isEmpty')->andThrow(new RuntimeException('boom'));
    $buffer->shouldReceive('workerStopping')->andThrow(new RuntimeException('boom'));
    $buffer->shouldReceive('report')->times(3);

    $flusher = new FlushPageViewBuffer($buffer);

    expect($flusher->onLooping(new Looping('database', 'default')))->toBeNull();

    $flusher->onJobProcessing(foreignJob());
    $flusher->onWorkerStopping(new WorkerStopping);
});
