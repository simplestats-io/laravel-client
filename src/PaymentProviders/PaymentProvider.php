<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

interface PaymentProvider
{
    public function isAvailable(): bool;

    public function register(): void;
}
