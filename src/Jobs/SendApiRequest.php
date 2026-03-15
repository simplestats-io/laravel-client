<?php

namespace SimpleStatsIo\LaravelClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use SimpleStatsIo\LaravelClient\Services\ApiConnector;

class SendApiRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected string $route;

    protected array $payload;

    protected string $method;

    /**
     * Retry up to 15 times to handle temporary unavailability or prolonged outages.
     * Backoff starts frequently (5s, 10s, 60s, ...) then slows to once per day,
     * giving roughly one week to resolve a major outage before attempts are exhausted.
     */
    public int $tries = 15;

    public array $backoff = [5, 10, 60, 600, 3600, 12 * 3600, 3600 * 24];

    public function __construct(string $route, array $payload, string $method = 'POST')
    {
        $this->route = $route;
        $this->payload = $payload;
        $this->method = $method;
        $this->queue = config('simplestats-client.queue');
    }

    public function handle(ApiConnector $apiConnector): void
    {
        try {
            $response = $apiConnector->request($this->route, $this->payload, $this->method);
        } catch (ConnectionException) {
            $response = null;
        }

        if ($response && $response->successful()) {
            return;
        }

        $attempt = $this->attempts();

        if ($attempt >= $this->tries) {
            Log::warning('SimpleStats API request failed after all retries.', [
                'route' => $this->route,
                'status' => $response?->status(),
            ]);
            $this->delete();

            return;
        }

        $backoffIndex = min($attempt - 1, count($this->backoff) - 1);
        $this->release($this->backoff[$backoffIndex]);
    }
}
