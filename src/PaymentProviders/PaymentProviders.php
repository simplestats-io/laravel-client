<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders;

class PaymentProviders
{
    public static function configured(): ?string
    {
        $provider = config('simplestats-client.tracking_types.payment.provider');

        return is_string($provider) && $provider !== '' ? $provider : null;
    }

    /**
     * Any class implementing PaymentProvider works, so apps can plug in their own provider too.
     */
    public static function make(?string $provider): ?PaymentProvider
    {
        return $provider !== null && is_subclass_of($provider, PaymentProvider::class) ? app($provider) : null;
    }
}
