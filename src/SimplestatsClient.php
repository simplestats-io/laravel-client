<?php

namespace SimpleStatsIo\LaravelClient;

use Illuminate\Foundation\Auth\User;
use Illuminate\Foundation\Bus\PendingDispatch;
use Laravel\SerializableClosure\SerializableClosure;
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

        $user = $this->unserializeClosure($trackingType['user_resolver'])($payment);

        $payload = [
            'stats_user_id' => $user->getKey(),
            'gross' => (float) $this->unserializeClosure($trackingType['calculator']['gross'])($payment),
            'net' => (float) $this->unserializeClosure($trackingType['calculator']['net'])($payment),
            'time' => $this->getTime($trackingType, $payment),
        ];

        return SendApiRequest::dispatch('stats-payment', $payload);
    }

    private function getTime($trackingType, $model = null)
    {
        return $this->unserializeClosure($trackingType['time_resolver'])($model)->format('Y-m-d');
    }

    private function unserializeClosure(SerializableClosure $serializedClosure): callable
    {
        return unserialize(serialize($serializedClosure))->getClosure();
    }
}
