<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUserWithCondition;

class UserWithCondition extends Authenticatable implements TrackableUserWithCondition
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
        return $this->email_verified_at != NULL;
    }

    public function getTrackingConditionFields(): array
    {
        return ['email_verified_at'];
    }

    public function getTrackingTime(): CarbonInterface
    {
        return $this->{self::CREATED_AT};
    }
}
