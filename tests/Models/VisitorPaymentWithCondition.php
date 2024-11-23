<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use SimplestatsClient;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;
use SimpleStatsIo\LaravelClient\Visitor;

class VisitorPaymentWithCondition extends Model implements TrackablePaymentWithCondition
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
        return now();
    }

    public function getTrackingPerson(): TrackablePerson
    {
        return new Visitor($this->visitor_hash);
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
}
