<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

use Carbon\CarbonInterface;

interface TrackableUser
{
    /**
     * The time when the user has registered.
     */
    public function getTrackingTime(): CarbonInterface;
}
