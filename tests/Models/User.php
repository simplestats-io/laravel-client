<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;

class User extends Authenticatable implements TrackableUser
{
    protected $guarded = [];

    public function getTrackingTime(): CarbonInterface
    {
        return $this->{self::CREATED_AT};
    }
}
