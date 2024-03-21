<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User;

interface TrackablePayment
{
    /**
     * Get the user associated with the payment.
     */
    public function getTrackingUser(): User;

    /**
     * Get the time when the payment happened.
     */
    public function getTrackingTime(): CarbonInterface;

    /**
     * Get the gross amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingGross(): float;

    /**
     * Get the net amount of the payment in cents ($1 = 100 Cent).
     */
    public function getTrackingNet(): float;

    /**
     * Get the ISO-4217 currency code currency of the payment.
     */
    public function getTrackingCurrency(): string;
}
