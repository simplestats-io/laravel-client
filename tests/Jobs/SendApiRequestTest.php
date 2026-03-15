<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Log;
use SimpleStatsIo\LaravelClient\Jobs\SendApiRequest;
use SimpleStatsIo\LaravelClient\Services\ApiConnector;

beforeEach(function () {
    $this->connector = Mockery::mock(ApiConnector::class);
});

function createJobWithAttempts(string $route, array $payload, int $attempts): SendApiRequest
{
    $job = new SendApiRequest($route, $payload);

    $fakeQueueJob = Mockery::mock(SyncJob::class);
    $fakeQueueJob->shouldReceive('getJobId')->andReturn('test-job-id');
    $fakeQueueJob->shouldReceive('attempts')->andReturn($attempts);
    $fakeQueueJob->shouldReceive('release')->withAnyArgs();
    $fakeQueueJob->shouldReceive('delete');
    $fakeQueueJob->shouldReceive('isReleased')->andReturn(false);
    $fakeQueueJob->shouldReceive('isDeleted')->andReturn(false);
    $fakeQueueJob->shouldReceive('isDeletedOrReleased')->andReturn(false);

    $job->setJob($fakeQueueJob);

    return $job;
}

it('completes successfully when API returns 200', function () {
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('successful')->andReturn(true);

    $this->connector->shouldReceive('request')
        ->with('stats-visitor', ['foo' => 'bar'], 'POST')
        ->once()
        ->andReturn($response);

    $job = createJobWithAttempts('stats-visitor', ['foo' => 'bar'], 1);
    $job->handle($this->connector);

    $job->job->shouldNotHaveReceived('release');
    $job->job->shouldNotHaveReceived('delete');
});

it('releases the job silently when API returns an error', function () {
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('successful')->andReturn(false);

    $this->connector->shouldReceive('request')->andReturn($response);

    $job = createJobWithAttempts('stats-visitor', ['foo' => 'bar'], 1);
    $job->handle($this->connector);

    $job->job->shouldHaveReceived('release')->with(5)->once();
});

it('releases the job silently on connection exception', function () {
    $this->connector->shouldReceive('request')
        ->andThrow(new ConnectionException('Connection timed out'));

    $job = createJobWithAttempts('stats-visitor', ['foo' => 'bar'], 1);
    $job->handle($this->connector);

    $job->job->shouldHaveReceived('release')->with(5)->once();
});

it('deletes the job and logs warning after all retries exhausted', function () {
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('successful')->andReturn(false);
    $response->shouldReceive('status')->andReturn(500);

    $this->connector->shouldReceive('request')->andReturn($response);

    Log::spy();

    $job = createJobWithAttempts('stats-visitor', ['foo' => 'bar'], 15);
    $job->handle($this->connector);

    $job->job->shouldHaveReceived('delete')->once();
    $job->job->shouldNotHaveReceived('release');

    Log::shouldHaveReceived('warning')
        ->with('SimpleStats API request failed after all retries.', Mockery::on(function ($context) {
            return $context['route'] === 'stats-visitor' && $context['status'] === 500;
        }))
        ->once();
});

it('uses correct backoff delay based on attempt number', function () {
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('successful')->andReturn(false);

    $this->connector->shouldReceive('request')->andReturn($response);

    $job = createJobWithAttempts('stats-visitor', ['foo' => 'bar'], 3);
    $job->handle($this->connector);

    $job->job->shouldHaveReceived('release')->with(60)->once(); // backoff[2] = 60
});

it('caps backoff index at the last backoff value', function () {
    $response = Mockery::mock(Response::class);
    $response->shouldReceive('successful')->andReturn(false);

    $this->connector->shouldReceive('request')->andReturn($response);

    $job = createJobWithAttempts('stats-visitor', ['foo' => 'bar'], 14);
    $job->handle($this->connector);

    $job->job->shouldHaveReceived('release')->with(3600 * 24)->once();
});
