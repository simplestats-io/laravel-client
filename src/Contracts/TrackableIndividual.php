<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

use Carbon\CarbonInterface;

interface TrackableIndividual
{
    /**
     * Get the value of the individual's primary key.
     *
     * @return mixed
     */
    public function getKey();

    /**
     * The time when the user has registered.
     */
    public function getTrackingTime(): CarbonInterface;
}
