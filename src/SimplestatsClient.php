<?php

namespace SimpleStatsIo\LaravelClient;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Context;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Events\CustomEventTracked;
use SimpleStatsIo\LaravelClient\Events\CustomPropertiesTracked;
use SimpleStatsIo\LaravelClient\Events\LoginTracked;
use SimpleStatsIo\LaravelClient\Events\PaymentTracked;
use SimpleStatsIo\LaravelClient\Events\UserTracked;
use SimpleStatsIo\LaravelClient\Events\VisitorTracked;
use SimpleStatsIo\LaravelClient\Jobs\SendApiRequest;
use SimpleStatsIo\LaravelClient\Services\CustomPropertiesResolver;
use SimpleStatsIo\LaravelClient\Storage\TrackingStorage;

class SimplestatsClient
{
    const TIME_FORMAT = 'Y-m-d H:i:s P';

    protected const CONTEXT_KEY_VISITOR_HASH = 'simplestats.visitor_hash';

    public function getVisitorHash(): ?string
    {
        return Context::get(self::CONTEXT_KEY_VISITOR_HASH);
    }

    public function setVisitorHash(string $visitorHash): void
    {
        Context::add(self::CONTEXT_KEY_VISITOR_HASH, $visitorHash);
    }

    /**
     * Whether the visitor of the current visit was actually tracked. No storage
     * entry means the CheckTracking middleware skipped this visit (bot, blocked
     * IP, except route), so the API will never know this visitor hash and any
     * request referencing it would only be dropped server-side. Storing
     * properties for such a visitor would even prevent the middleware from
     * tracking it later (see CheckTracking::doTracking()).
     */
    public function isTrackedVisitor(TrackablePerson $person): bool
    {
        return app(TrackingStorage::class)->has($person->getKey());
    }

    public function trackVisitor(TrackablePerson $visitor): void
    {
        $trackingData = $this->getTrackingData();

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
            'properties' => $trackingData['properties'] ?? null,
            'time' => $this->getTime(now()),
        ];

        event(new VisitorTracked($visitor, $payload));

        safeDefer(fn () => SendApiRequest::dispatch('stats-visitor', $payload));
    }

    /**
     * @param  TrackablePerson&Model  $user
     */
    public function trackLogin(TrackablePerson $user): void
    {
        $trackingData = $this->getTrackingData();

        $payload = [
            'stats_user_id' => $user->getKey(),
            'stats_user_time' => $this->getTime($user->getTrackingTime()),
            'ip' => $trackingData['ip'] ?? null,
            'user_agent' => $trackingData['user_agent'] ?? null,
            'time' => $this->getTime(now()),
        ];

        event(new LoginTracked($user, $payload));

        safeDefer(fn () => SendApiRequest::dispatch('stats-login', $payload));
    }

    /**
     * @param  TrackablePerson&Model  $user
     */
    public function trackUser(TrackablePerson $user, bool $addLogin = false): void
    {
        $trackingData = $this->getTrackingData();

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

        // Resolved user properties win over inherited visitor properties on
        // name conflicts. array_replace instead of array_merge, so numeric
        // property names (e.g. '2024') overwrite by name instead of being
        // re-indexed and appended.
        $payload['properties'] = array_replace(
            $trackingData['properties'] ?? [],
            app(CustomPropertiesResolver::class)->forUser($user),
        );

        event(new UserTracked($user, $payload));

        safeDefer(fn () => SendApiRequest::dispatch('stats-user', $payload));
    }

    /**
     * @param  TrackablePayment&Model  $payment
     *
     * @throws Exception
     */
    public function trackPayment(TrackablePayment $payment): void
    {
        $payload = $this->applyPersonAttribution([
            'id' => $payment->getKey(),
            'gross' => $payment->getTrackingGross(),
            'net' => $payment->getTrackingNet(),
            'currency' => $payment->getTrackingCurrency(),
            'time' => $this->getTime($payment->getTrackingTime()),
        ], $payment->getTrackingPerson(), requireTrackedVisitor: false);

        event(new PaymentTracked($payment, $payload));

        safeDefer(fn () => SendApiRequest::dispatch('stats-payment', $payload));
    }

    public function trackCustomEvent(string $id, string $name, TrackablePerson $person): void
    {
        $payload = $this->applyPersonAttribution([
            'id' => $id,
            'name' => $name,
            'time' => $this->getTime(now()),
        ], $person);

        // events for a never tracked visitor could never be attributed
        if ($payload === null) {
            return;
        }

        event(new CustomEventTracked($id, $name, $person, $payload));

        safeDefer(fn () => SendApiRequest::dispatch('stats-custom-event', $payload));
    }

    /**
     * @param  array<string, scalar|null>  $properties
     */
    public function trackCustomProperties(array $properties, TrackablePerson $person): void
    {
        $payload = $this->applyPersonAttribution([
            'properties' => $properties,
            'time' => $this->getTime(now()),
        ], $person);

        // nothing to send, store or inherit for a never tracked visitor
        if ($payload === null) {
            return;
        }

        // The StoreVisitorProperties listener persists visitor properties for
        // the inheritance on sign-up (see trackUser()).
        event(new CustomPropertiesTracked($properties, $person, $payload));

        safeDefer(fn () => SendApiRequest::dispatch('stats-custom-properties', $payload));
    }

    /**
     * Add the user vs. visitor attribution to the payload. With
     * $requireTrackedVisitor, null is returned for a never tracked visitor
     * (see isTrackedVisitor()) and callers must abort. trackPayment() opts
     * out of that guard, payments are never dropped client-side.
     *
     * @return ($requireTrackedVisitor is true ? array|null : array)
     */
    protected function applyPersonAttribution(array $payload, TrackablePerson $person, bool $requireTrackedVisitor = true): ?array
    {
        $userModel = config('simplestats-client.tracking_types.user.model');

        if ($person instanceof $userModel) {
            $payload['stats_user_id'] = $person->getKey();
            $payload['stats_user_time'] = $this->getTime($person->getTrackingTime());

            return $payload;
        }

        if ($requireTrackedVisitor && ! $this->isTrackedVisitor($person)) {
            return null;
        }

        $payload['visitor_hash'] = $person->getKey();

        return $payload;
    }

    protected function getTrackingData(): Collection
    {
        return app(TrackingStorage::class)->get($this->getVisitorHash());
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
