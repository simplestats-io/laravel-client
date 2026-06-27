<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;
use SimpleStatsIo\LaravelClient\Visitor;

interface TrackablePayment
{
    /**
     * The person associated with the payment.
     * This may either be a user or a visitor.
     */
    public function getTrackingPerson(): TrackablePerson;

    /**
     * The time when the payment happened.
     */
    public function getTrackingTime(): CarbonInterface;

    /**
     * The gross amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingGross(): float;

    /**
     * The net amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingNet(): float;

    /**
     * The ISO-4217 currency code of the payment.
     */
    public function getTrackingCurrency(): string;

    /**
     * The subscription a recurring payment belongs to (plan and billing
     * interval), or null for a one-time payment.
     */
    public function getTrackingSubscription(): ?TrackingSubscription;
}
