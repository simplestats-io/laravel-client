<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

use Carbon\CarbonInterface;
use Illuminate\Foundation\Auth\User;

interface TrackablePayment
{
    /**
     * Get the user associated with the payment.
     *
     * @return User
     */
    public function getTrackingUser(): User;

    /**
     * Get the time when the payment happened.
     *
     * @return CarbonInterface
     */
    public function getTrackingTime(): CarbonInterface;

    /**
     * Get the gross amount of the payment.
     *
     * @return float
     */
    public function getTrackingGross(): float;

    /**
     * Get the net amount of the payment.
     *
     * @return float
     */
    public function getTrackingNet(): float;

    /**
     * Get the ISO-4217 currency code currency of the payment.
     *
     * @return float
     */
    public function getTrackingCurrency(): string;
}
