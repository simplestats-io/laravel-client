<?php

namespace SimpleStatsIo\LaravelClient\Tests\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Visitor;

class VisitorPayment extends Model implements TrackablePayment
{
    protected $table = 'payments';

    protected $guarded = [];

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
