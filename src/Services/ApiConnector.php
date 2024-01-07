<?php

namespace LaracraftTech\SimplestatsClient\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use LaracraftTech\SimplestatsClient\Enums\HttpMethod;
use LaracraftTech\SimplestatsClient\Exceptions\ApiRequestFailed;

class ApiConnector
{
    private PendingRequest $httpClient;

    public function __construct(string $apiUrl, string $apiToken)
    {
        $this->httpClient = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiToken,
            'Accept' => 'application/json',
        ])->baseUrl($apiUrl);
    }

    /**
     * @throws ApiRequestFailed
     */
    public function request(string $route, array $payload, HttpMethod $method = HttpMethod::POST)
    {
        $method = strtolower($method->value);
        $response = $this->httpClient->$method($route, $payload);

        if (! $response->successful()) {
            throw new ApiRequestFailed('Reason: '. $response->json()['message'] ?? 'unknown', $response->status());
        }

        return $response->json();
    }
}
