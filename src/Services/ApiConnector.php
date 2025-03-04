<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use SimpleStatsIo\LaravelClient\Exceptions\ApiRequestFailed;

class ApiConnector
{
    protected PendingRequest $httpClient;

    public function __construct(string $apiUrl, string $apiToken)
    {
        $this->httpClient = Http::baseUrl($apiUrl)
            ->withHeaders([
                'X-SimpleStats-Client-Version' => getSimpleStatsVersion(),
            ])
            ->withToken($apiToken)
            ->timeout(5)
            ->acceptJson();
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
