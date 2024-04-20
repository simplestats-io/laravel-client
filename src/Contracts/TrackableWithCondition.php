<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

interface TrackableWithCondition
{
    /**
     * The condition that should be fulfilled in order to track the model.
     */
    public function passTrackingCondition(): bool;

    /**
     * The field(s) we should watch for changes to recheck the condition.
     */
    public function watchTrackingFields(): array;
}
