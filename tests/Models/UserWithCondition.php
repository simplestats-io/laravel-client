<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePersonWithCondition;

class UserWithCondition extends Authenticatable implements TrackablePersonWithCondition
{
    protected $table = 'users';

    protected $guarded = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    public function passTrackingCondition(): bool
    {
        return $this->email_verified_at != null;
    }

    public function watchTrackingFields(): array
    {
        return ['email_verified_at'];
    }

    public function getTrackingTime(): CarbonInterface
    {
        return $this->{self::CREATED_AT};
    }
}
