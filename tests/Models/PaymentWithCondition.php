<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUserWithCondition;

class PaymentWithCondition extends Authenticatable implements TrackablePaymentWithCondition
{
    protected $table = 'payments';
    protected $guarded = [];

    public function passTrackingCondition(): bool
    {
        return $this->status == 'completed';
    }

    public function getTrackingConditionFields(): array
    {
        return ['status'];
    }

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
