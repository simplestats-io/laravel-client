<?php

namespace SimpleStatsIo\LaravelClient;

use Carbon\CarbonInterface;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;

class Visitor implements TrackablePerson
{
    private string $key;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function getKey()
    {
        return $this->key;
    }

    public function getTrackingTime(): CarbonInterface
    {
        return now();
    }
}
