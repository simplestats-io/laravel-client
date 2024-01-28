<?php

namespace SimpleStatsIo\LaravelClient;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bus\PendingDispatch;
use SimpleStatsIo\LaravelClient\Jobs\SendApiRequest;

class SimplestatsClient
{
    public function trackLogin(User $user): PendingDispatch
    {
        $trackingType = config('simplestats-client.tracking_types.login');

        $payload = [
            'stats_user_id' => $user->getKey(),
            'time' => $this->getTime($trackingType),
        ];

        return SendApiRequest::dispatch('stats-login', $payload);
    }

    public function trackUser(User $user): PendingDispatch
    {
        $trackingData = session('simplestats.tracking');
        $trackingType = config('simplestats-client.tracking_types.user');

        $payload = [
            'id' => $user->getKey(),
            'track_source' => $trackingData['source'] ?? null,
            'track_medium' => $trackingData['medium'] ?? null,
            'track_campaign' => $trackingData['campaign'] ?? null,
            'track_term' => $trackingData['term'] ?? null,
            'track_content' => $trackingData['content'] ?? null,
            'time' => $this->getTime($trackingType, $user),
        ];

        return SendApiRequest::dispatch('stats-user', $payload);
    }

    public function trackPayment($payment): PendingDispatch
    {
        $trackingType = config('simplestats-client.tracking_types.payment');

        $user = $trackingType['user_resolver']($payment);

        $payload = [
            'stats_user_id' => $user->getKey(),
            'gross' => (float) $trackingType['calculator']['gross']($payment),
            'net' => (float) $trackingType['calculator']['net']($payment),
            'time' => $this->getTime($trackingType, $payment),
        ];

        return SendApiRequest::dispatch('stats-payment', $payload);
    }

    private function getTime($trackingType, $model = null)
    {
        return $trackingType['time_resolver']($model)->setTimezone('UTC')->format('Y-m-d H:i:s');
    }
}
