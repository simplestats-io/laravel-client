<?php

namespace SimpleStatsIo\LaravelClient;

use Illuminate\Foundation\Bus\PendingDispatch;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;
use SimpleStatsIo\LaravelClient\Jobs\SendApiRequest;

class SimplestatsClient
{
    const TIME_FORMAT = 'Y-m-d H:i:s';

    public function trackLogin(TrackableUser $user): PendingDispatch
    {
        $payload = [
            'stats_user_id' => $user->getKey(),
            'time' => now()->format(self::TIME_FORMAT),
        ];

        return SendApiRequest::dispatch('stats-login', $payload);
    }

    public function trackUser(TrackableUser $user): PendingDispatch
    {
        $trackingData = session('simplestats.tracking');

        $payload = [
            'id' => $user->getKey(),
            'track_source' => $trackingData['source'] ?? null,
            'track_medium' => $trackingData['medium'] ?? null,
            'track_campaign' => $trackingData['campaign'] ?? null,
            'track_term' => $trackingData['term'] ?? null,
            'track_content' => $trackingData['content'] ?? null,
            'time' => $user->getTrackingTime()->format(self::TIME_FORMAT),
        ];

        return SendApiRequest::dispatch('stats-user', $payload);
    }

    public function trackPayment(TrackablePayment $payment): PendingDispatch
    {
        $payload = [
            'id' => $payment->getKey(),
            'stats_user_id' => $payment->getTrackingUser()->getKey(),
            'gross' => $payment->getTrackingGross(),
            'net' => $payment->getTrackingNet(),
            'currency' => $payment->getTrackingCurrency(),
            'time' => $payment->getTrackingTime()->format(self::TIME_FORMAT),
        ];

        return SendApiRequest::dispatch('stats-payment', $payload);
    }
}
