<?php

namespace LaracraftTech\SimplestatsClient\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LaracraftTech\SimplestatsClient\Enums\HttpMethod;

class ApiConnector
{
    private PendingRequest $httpClient;

    public function __construct(string $apiUrl, string $apiToken)
    {
        $this->httpClient = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiToken,
        ])->baseUrl($apiUrl);
    }

    public function request(string $route, array $payload, HttpMethod $method = HttpMethod::POST)
    {
        $method = strtolower($method->value);

        return $this->httpClient->$method($route, $payload)->json();
    }
}
