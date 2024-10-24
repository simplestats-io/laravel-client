<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SimpleStatsIo\LaravelClient\Contracts\TrackableIndividual;

class User extends Authenticatable implements TrackableIndividual
{
    protected $guarded = [];

    public function getTrackingTime(): CarbonInterface
    {
        return $this->{self::CREATED_AT};
    }
}
