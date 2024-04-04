<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SimpleStatsIo\LaravelClient\Exceptions\ApiRequestFailed;

class ApiConnector
{
    private PendingRequest $httpClient;

    public function __construct(string $apiUrl, string $apiToken)
    {
        $this->httpClient = Http::baseUrl($apiUrl)->withToken($apiToken)->acceptJson();
    }

    /**
     * @throws ApiRequestFailed
     */
    public function request(string $route, array $payload, string $method = 'POST')
    {
        $method = strtolower($method);
        $response = $this->httpClient->$method($route, $payload);

        if (! $response->successful()) {
            $json = array_merge(['message' => 'unknown'], $response->json() ?? []);
            throw new ApiRequestFailed('Reason: '.$json['message'], $response->status());
        }

        return $response->json();
    }
}
