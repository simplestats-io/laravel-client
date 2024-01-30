<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

use Carbon\CarbonInterface;

interface TrackableUser
{
    /**
     * Get the time when the user registered.
     *
     * @return CarbonInterface
     */
    public function getTrackingTime(): CarbonInterface;

}
