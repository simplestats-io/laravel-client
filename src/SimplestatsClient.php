<?php

namespace LaracraftTech\SimplestatsClient;

use App\Models\User;
use Illuminate\Support\Facades\Http;

class SimplestatsClient
{
    private $httpClient;

    public function __construct(string $apiUrl, string $apiToken)
    {
        $this->httpClient = Http::withHeaders([
            'Authorization' => 'Bearer '.$apiToken,
        ])->baseUrl($apiUrl.'/api/');
    }

    public function trackRegistration(User $user)
    {
        $payload = [
            'created_at' => $user->{$user::CREATED_AT}->format('Y-m-d H:i:s'),
            'track_source' => session('simplestats.tracking.source') ?? '',
            'track_medium' => session('simplestats.tracking.medium') ?? '',
            'track_campaign' => session('simplestats.tracking.campaign') ?? '',
            'track_term' => session('simplestats.tracking.term') ?? '',
            'track_content' => session('simplestats.tracking.content') ?? '',
        ];

        return $this->httpClient->post('stats-user', $payload)->json();
    }
}
