<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

use Carbon\CarbonInterface;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Data\TrackingSubscription;

class ProviderPayment implements TrackablePayment
{
    /**
     * Providers send amounts in the smallest currency unit, the API expects cents, which differs
     * for currencies without decimals: 1000 JPY are 1000 units, but 100000 "cents".
     */
    protected const ZERO_DECIMAL_CURRENCIES = [
        'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF',
    ];

    protected string $currency;

    /**
     * @param  float  $gross  in the smallest currency unit
     * @param  float  $net  in the smallest currency unit
     */
    public function __construct(
        protected int|string $id,
        protected TrackablePerson $person,
        protected CarbonInterface $time,
        protected float $gross,
        protected float $net,
        string $currency,
        protected ?TrackingSubscription $subscription = null,
    ) {
        $this->currency = strtoupper($currency);

        if (in_array($this->currency, self::ZERO_DECIMAL_CURRENCIES, true)) {
            $this->gross *= 100;
            $this->net *= 100;
        }
    }

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
