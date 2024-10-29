<?php

namespace SimpleStatsIo\LaravelClient;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;
use SimpleStatsIo\LaravelClient\Jobs\SendApiRequest;

class SimplestatsClient
{
    const TIME_FORMAT = 'Y-m-d H:i:s P';

    public function trackVisitor(): void
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
            'time' => $this->getTime(now()),
        ];

        defer(fn () => SendApiRequest::dispatch('stats-visitor', $payload));
    }

    /**
     * @param  TrackableUser&Model  $user
     */
    public function trackLogin(TrackableUser $user): void
    {
        $trackingData = $this->getSessionTracking();

        $payload = [
            'stats_user_id' => $user->getKey(),
            'stats_user_time' => $this->getTime($user->getTrackingTime()),
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => (isset($trackingData['user_agent'])) ? urlencode($trackingData['user_agent']) : null,
            'time' => $this->getTime(now()),
        ];

        defer(fn () => SendApiRequest::dispatch('stats-login', $payload));
    }

    /**
     * @param  TrackableUser&Model  $user
     */
    public function trackUser(TrackableUser $user, bool $addLogin = false): void
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
            'add_login' => $addLogin,
            'time' => $this->getTime($user->getTrackingTime()),
        ];

        defer(fn () => SendApiRequest::dispatch('stats-user', $payload));
    }

    /**
     * @param  TrackablePayment&Model  $payment
     */
    public function trackPayment(TrackablePayment $payment): void
    {
        $user = $payment->getTrackingUser();

        $payload = [
            'id' => $payment->getKey(),
            'stats_user_id' => $user->getKey(),
            // @phpstan-ignore-next-line
            'stats_user_time' => $this->getTime($user->getTrackingTime()),
            'gross' => $payment->getTrackingGross(),
            'net' => $payment->getTrackingNet(),
            'currency' => $payment->getTrackingCurrency(),
            'time' => $this->getTime($payment->getTrackingTime()),
        ];

        defer(fn () => SendApiRequest::dispatch('stats-payment', $payload));
    }

    protected function getSessionTracking(): Collection
    {
        return session('simplestats.tracking') ?? collect();
    }

    /**
     * We want all dates in UTC
     */
    protected function getTime(CarbonInterface $time): string
    {
        return $time->tz('UTC')->format(self::TIME_FORMAT);
    }
}
