<?php

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use SimpleStatsIo\LaravelClient\Jobs\SendPageViews;
use SimpleStatsIo\LaravelClient\Listeners\FlushPageViewBuffer;

beforeEach(function () {
    if (! extension_loaded('pcntl')) {
        $this->markTestSkipped('Buffering needs the pcntl extension.');
    }

    $this->loadLaravelMigrations();

    config(['queue.default' => 'database']);

    // The provider only registers these at boot, before the test switched away from sync.
    Event::listen(Looping::class, [FlushPageViewBuffer::class, 'onLooping']);
    Event::listen(JobProcessing::class, [FlushPageViewBuffer::class, 'onJobProcessing']);
    Event::listen(WorkerStopping::class, [FlushPageViewBuffer::class, 'onWorkerStopping']);

    Http::fake([config('simplestats-client.api_url').'*' => Http::response(['accepted' => 2], 200)]);
});

it('bundles view jobs inside a real queue worker into one batch', function () {
    $view = fn (string $page) => ['h' => 'visitor-a', 't' => 1794687300, 'n' => 1, 'p' => $page];

    Bus::dispatch(new SendPageViews([$view('/pricing')]));
    Bus::dispatch(new SendPageViews([$view('/pricing')]));
    Bus::dispatch(new SendPageViews([$view('/docs')]));

    $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();

    Http::assertNothingSent();
    expect(DB::table('jobs')->count())->toBe(1);

    $this->artisan('queue:work', ['--stop-when-empty' => true, '--sleep' => 0])->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['meta'] === ['mode' => 'worker']
        && $request['views'] === [[...$view('/pricing'), 'n' => 2], $view('/docs')]);

    expect(DB::table('jobs')->count())->toBe(0);
});
