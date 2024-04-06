<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

interface TrackableWithCondition
{
    /**
     * The condition that should be fulfilled in order to track the model.
     */
    public function passTrackingCondition(): bool;

    /**
     * The field(s) we should listen for changes to recheck the condition.
     */
    public function getTrackingConditionFields(): array;
}
