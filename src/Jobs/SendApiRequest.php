<?php

namespace LaracraftTech\SimplestatsClient\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LaracraftTech\SimplestatsClient\Enums\HttpMethod;
use LaracraftTech\SimplestatsClient\Services\ApiConnector;

class SendApiRequest implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Let's retry 15 times (if the stats tool is temporary not reachable or has a heavy outage :D)
     * First we try more often and after seven times, we only try every day (the last item in the backoff array)
     * Means we have one week to fix a heavy outage... :D
     *
     * @var int
     */
    public int $tries = 15;
    public array $backoff = [5, 10, 60, 600, 3600, 12*3600, 3600*24];

    public function __construct(
        private readonly string $route,
        private readonly array $payload,
        private readonly HttpMethod $method = HttpMethod::POST
    ) {
        $this->queue = config('simplestats-client.queue');
    }

    public function handle(ApiConnector $apiConnector): void
    {
        $apiConnector->request($this->route, $this->payload, $this->method);
    }
}
