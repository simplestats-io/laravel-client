<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;

class Payment extends Authenticatable implements TrackablePayment
{
    protected $guarded = [];

    public function getTrackingTime(): CarbonInterface
    {
        return $this->{self::CREATED_AT};
    }

    public function getTrackingUser(): Authenticatable
    {
        return $this->user;
    }

    public function getTrackingGross(): float
    {
        return 1000;
    }

    public function getTrackingNet(): float
    {
        return 800;
    }

    public function getTrackingCurrency(): string
    {
        return 'USD';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
