<?php

namespace SimpleStatsIo\LaravelClient;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Collection;
use SimpleStatsIo\LaravelClient\Contracts\TrackableIndividual;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Jobs\SendApiRequest;

class SimplestatsClient
{
    const TIME_FORMAT = 'Y-m-d H:i:s P';

    /**
     * @param  TrackableIndividual  $visitor
     */
    public function trackVisitor(TrackableIndividual $visitor): PendingDispatch
    {
        $trackingData = $this->getSessionTracking();

        $payload = [
            'visitor_hash' => $visitor->getKey(),
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => $trackingData['user_agent'] ?? null,
            'track_referer' => $trackingData['referer'] ?? null,
            'track_source' => $trackingData['source'] ?? null,
            'track_medium' => $trackingData['medium'] ?? null,
            'track_campaign' => $trackingData['campaign'] ?? null,
            'track_term' => $trackingData['term'] ?? null,
            'track_content' => $trackingData['content'] ?? null,
            'page_entry' => $trackingData['page'] ?? null,
            'time' => $this->getTime(now()),
        ];

        return SendApiRequest::dispatch('stats-visitor', $payload);
    }

    /**
     * @param  TrackableIndividual&Model  $user
     */
    public function trackLogin(TrackableIndividual $user): PendingDispatch
    {
        $trackingData = $this->getSessionTracking();

        $payload = [
            'stats_user_id' => $user->getKey(),
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => $trackingData['user_agent'] ?? null,
            'time' => $this->getTime(now()),
        ];

        return SendApiRequest::dispatch('stats-login', $payload);
    }

    /**
     * @param  TrackableIndividual&Model  $user
     */
    public function trackUser(TrackableIndividual $user, bool $addLogin = false): PendingDispatch
    {
        $trackingData = $this->getSessionTracking();

        $payload = [
            'id' => $user->getKey(),
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => $trackingData['user_agent'] ?? null,
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

        return SendApiRequest::dispatch('stats-user', $payload);
    }

    /**
     * @param  TrackablePayment&Model  $payment
     *
     * @throws Exception
     */
    public function trackPayment(TrackablePayment $payment): PendingDispatch
    {
        $payload = [
            'id' => $payment->getKey(),
            'gross' => $payment->getTrackingGross(),
            'net' => $payment->getTrackingNet(),
            'currency' => $payment->getTrackingCurrency(),
            'time' => $this->getTime($payment->getTrackingTime()),
        ];

        $userModel = config('simplestats-client.tracking_types.user.model');

        $trackingIndividual = $payment->getTrackingIndividual();
        $trackingIndividualParam = ($trackingIndividual instanceof $userModel) ? 'stats_user_id' : 'visitor_hash';
        $payload[$trackingIndividualParam] = $trackingIndividual->getKey();

        return SendApiRequest::dispatch('stats-payment', $payload);
    }

    protected function getSessionTracking(): Collection
    {
        return session('simplestats.tracking') ?? collect();
    }q

    public function getTime(CarbonInterface $time): string
    {
        return $time->tz('UTC')->format(self::TIME_FORMAT);
    }

    /**
     * A unique hash that identifies the visit at this day.
     */
    public function createVisitorHash(?string $time, ?string $ip, ?string $userAgent): string
    {
        $time = Carbon::parse($time);
        $visitorHash = hash('sha256', $ip.$userAgent.$time?->format('Y-m-d').config('app.key'));

        return substr($visitorHash, 0, 32);
    }
}
