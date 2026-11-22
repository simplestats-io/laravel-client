<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

use Carbon\CarbonInterface;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;

class ProviderPayment implements TrackablePayment
{
    public function __construct(
        protected int|string $id,
        protected TrackablePerson $person,
        protected CarbonInterface $time,
        protected float $gross,
        protected float $net,
        protected string $currency,
        protected ?TrackingSubscription $subscription = null,
    ) {}

    /**
     * SimplestatsClient::trackPayment() reads the payment id via getKey(), like on an Eloquent model.
     */
    public function getKey(): int|string
    {
        return $this->id;
    }

    public function getTrackingPerson(): TrackablePerson
    {
        return $this->person;
    }

    public function getTrackingTime(): CarbonInterface
    {
        return $this->time;
    }

    public function getTrackingGross(): float
    {
        return $this->gross;
    }

    public function getTrackingNet(): float
    {
        return $this->net;
    }

    public function getTrackingCurrency(): string
    {
        return $this->currency;
    }

    public function getTrackingSubscription(): ?TrackingSubscription
    {
        return $this->subscription;
    }
}
