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
