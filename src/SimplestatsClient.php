<?php

namespace SimpleStatsIo\LaravelClient;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;
use SimpleStatsIo\LaravelClient\Jobs\SendApiRequest;

class SimplestatsClient
{
    const TIME_FORMAT = 'Y-m-d';

    public function trackVisitor(): PendingDispatch
    {
        $trackingData = $this->getSessionTracking();

        $payload = [
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => (isset($trackingData['user_agent'])) ? urlencode($trackingData['user_agent']) : null,
            'track_referer' => $trackingData['referer'] ?? null,
            'track_source' => $trackingData['source'] ?? null,
            'track_medium' => $trackingData['medium'] ?? null,
            'track_campaign' => $trackingData['campaign'] ?? null,
            'track_term' => $trackingData['term'] ?? null,
            'track_content' => $trackingData['content'] ?? null,
            'page_entry' => $trackingData['page'] ?? null,
            'time' => now()->format(self::TIME_FORMAT),
        ];

        return SendApiRequest::dispatch('stats-visitor', $payload);
    }

    /**
     * @param  TrackableUser&Model  $user
     */
    public function trackLogin(TrackableUser $user): PendingDispatch
    {
        $trackingData = $this->getSessionTracking();

        $payload = [
            'stats_user_id' => $user->getKey(),
            'time' => now()->format(self::TIME_FORMAT),
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => (isset($trackingData['user_agent'])) ? urlencode($trackingData['user_agent']) : null,
        ];

        return SendApiRequest::dispatch('stats-login', $payload);
    }

    /**
     * @param  TrackableUser&Model  $user
     */
    public function trackUser(TrackableUser $user): PendingDispatch
    {
        $trackingData = $this->getSessionTracking();

        $payload = [
            'id' => $user->getKey(),
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => (isset($trackingData['user_agent'])) ? urlencode($trackingData['user_agent']) : null,
            'track_referer' => $trackingData['referer'] ?? null,
            'track_source' => $trackingData['source'] ?? null,
            'track_medium' => $trackingData['medium'] ?? null,
            'track_campaign' => $trackingData['campaign'] ?? null,
            'track_term' => $trackingData['term'] ?? null,
            'track_content' => $trackingData['content'] ?? null,
            'page_entry' => $trackingData['page'] ?? null,
            'time' => $user->getTrackingTime()->format(self::TIME_FORMAT),
        ];

        return SendApiRequest::dispatch('stats-user', $payload);
    }

    /**
     * @param  TrackablePayment&Model  $payment
     */
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

    private function getSessionTracking(): array
    {
        return session('simplestats.tracking') ?? [];
    }
}
