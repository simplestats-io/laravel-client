<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

use Carbon\CarbonInterface;

interface TrackablePerson
{
    /**
     * Get the value of the person's primary key.
     *
     * @return mixed
     */
    public function getKey();

    /**
     * The time when the user has registered.
     */
    public function getTrackingTime(): CarbonInterface;
}
