<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;

class UserPaymentWithCondition extends Model implements TrackablePaymentWithCondition
{
    protected $table = 'payments';

    protected $guarded = [];

    public function passTrackingCondition(): bool
    {
        return $this->status == 'completed';
    }

    public function watchTrackingFields(): array
    {
        return ['status'];
    }

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
