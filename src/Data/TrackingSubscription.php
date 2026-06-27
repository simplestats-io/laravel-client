<?php

namespace SimpleStatsIo\LaravelClient\Data;

use SimpleStatsIo\LaravelClient\Enums\SubscriptionInterval;

class TrackingSubscription
{
    public function __construct(
        public ?string $plan = null,
        public ?SubscriptionInterval $interval = null,
    ) {}
}
