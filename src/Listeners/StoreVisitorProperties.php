<?php

namespace SimpleStatsIo\LaravelClient\Listeners;

use SimpleStatsIo\LaravelClient\Events\CustomPropertiesTracked;
use SimpleStatsIo\LaravelClient\Storage\TrackingStorage;

/**
 * Remember properties tracked for a visitor alongside its attribution data,
 * so a later registration in this visit inherits them. Properties tracked
 * for a user need no local copy, they are already bound to the registration.
 */
class StoreVisitorProperties
{
    public function __construct(
        protected TrackingStorage $storage,
    ) {}

    public function handle(CustomPropertiesTracked $event): void
    {
        // The client only sets a visitor_hash for tracked visitors; user
        // tracks carry a stats_user_id instead.
        if (! $visitorHash = $event->payload['visitor_hash'] ?? null) {
            return;
        }

        $trackingData = $this->storage->get($visitorHash);
        $storedProperties = $trackingData['properties'] ?? [];

        $trackingData->put('properties', array_replace($storedProperties, $event->properties));
        $this->storage->put($visitorHash, $trackingData);
    }
}
