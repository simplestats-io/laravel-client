<?php

namespace SimpleStatsIo\LaravelClient;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Jobs\SendApiRequest;

class SimplestatsClient
{
    const TIME_FORMAT = 'Y-m-d H:i:s P';

    public function trackVisitor(TrackablePerson $visitor): void
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

        safeDefer(fn () => SendApiRequest::dispatch('stats-visitor', $payload));
    }

    /**
     * @param  TrackablePerson&Model  $user
     */
    public function trackLogin(TrackablePerson $user): void
    {
        $trackingData = $this->getSessionTracking();

        $payload = [
            'stats_user_id' => $user->getKey(),
            'stats_user_time' => $this->getTime($user->getTrackingTime()),
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => $trackingData['user_agent'] ?? null,
            'time' => $this->getTime(now()),
        ];

        safeDefer(fn () => SendApiRequest::dispatch('stats-login', $payload));
    }

    /**
     * @param  TrackablePerson&Model  $user
     */
    public function trackUser(TrackablePerson $user, bool $addLogin = false): void
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

        safeDefer(fn () => SendApiRequest::dispatch('stats-user', $payload));
    }

    /**
     * @param  TrackablePayment&Model  $payment
     *
     * @throws Exception
     */
    public function trackPayment(TrackablePayment $payment): void
    {
        $payload = [
            'id' => $payment->getKey(),
            'gross' => $payment->getTrackingGross(),
            'net' => $payment->getTrackingNet(),
            'currency' => $payment->getTrackingCurrency(),
            'time' => $this->getTime($payment->getTrackingTime()),
        ];

        $userModel = config('simplestats-client.tracking_types.user.model');
        $trackingPerson = $payment->getTrackingPerson();

        if ($trackingPerson instanceof $userModel) {
            $payload['stats_user_id'] = $trackingPerson->getKey();
            $payload['stats_user_time'] = $this->getTime($trackingPerson->getTrackingTime());
        } else {
            $payload['visitor_hash'] = $trackingPerson->getKey();
        }

        safeDefer(fn () => SendApiRequest::dispatch('stats-payment', $payload));
    }

    protected function getSessionTracking(): Collection
    {
        return session('simplestats.tracking') ?? collect();
    }

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
        $visitorHash = hash('sha256', $ip.$userAgent.$time->format('Y-m-d').config('app.key'));

        return substr($visitorHash, 0, 32);
    }
}
