<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
     * @throws ConnectionException
     */
    public function request(string $route, array $payload, string $method = 'POST'): Response
    {
        $method = strtolower($method);

        return $this->httpClient->$method($route, $payload);
    }
}
