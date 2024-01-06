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
        ])->baseUrl($apiUrl);
    }

    public function trackRegistration(User $user)
    {
        $trackingData = session('simplestats.tracking');

        $payload = [
            'id' => $user->getKey(),
            'track_source' => $trackingData['source'] ?? '',
            'track_medium' => $trackingData['medium'] ?? '',
            'track_campaign' => $trackingData['campaign'] ?? '',
            'track_term' => $trackingData['term'] ?? '',
            'track_content' => $trackingData['content'] ?? '',
            'created_at' => $user->{$user::CREATED_AT}->format('Y-m-d H:i:s'),
        ];

        return $this->httpClient->post('stats-user', $payload)->json();
    }

    public function trackLogin(User $user)
    {
        $payload = [
            'stats_user_id' => $user->getKey(),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        return $this->httpClient->post('stats-login', $payload)->json();
    }
}
