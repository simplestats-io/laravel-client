<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;

class UserPayment extends Model implements TrackablePayment
{
    protected $table = 'payments';

    protected $guarded = [];

    public function getTrackingTime(): CarbonInterface
    {
        return $this->{self::CREATED_AT};
    }

    public function getTrackingPerson(): TrackablePerson
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
