<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

interface TrackableWithCondition
{
    /**
     * Define the condition to track the model.
     */
    public function passTrackingCondition(): bool;

    /**
     * Get the field(s) used for the tracking condition.
     */
    public function getTrackingConditionFields(): array;
}
