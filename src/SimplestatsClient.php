<?php

namespace LaracraftTech\SimplestatsClient;

use App\Models\User;
use LaracraftTech\SimplestatsClient\Jobs\SendApiRequest;
use LaracraftTech\SimplestatsClient\Services\ApiConnector;

class SimplestatsClient
{
    public function __construct(
        private ApiConnector $apiConnector
    ) {
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

        return SendApiRequest::dispatch('stats-user', $payload);
    }

    public function trackLogin(User $user)
    {
        $payload = [
            'stats_user_id' => $user->getKey(),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        return SendApiRequest::dispatch('stats-login', $payload);
    }
}
