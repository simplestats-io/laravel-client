<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

interface TrackableWithCondition
{
    /**
     * Define the condition to track the model.
     *
     * @return bool
     */
    public function passTrackingCondition(): bool;

    /**
     * Get the field(s) used for the tracking condition.
     *
     * @return array
     */
    public function getTrackingConditionFields(): array;
}
