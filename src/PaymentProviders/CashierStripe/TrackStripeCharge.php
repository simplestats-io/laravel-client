<?php

namespace SimpleStatsIo\LaravelClient\PaymentProviders\CashierStripe;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;
use Stripe\Exception\InvalidRequestException;

class TrackStripeCharge implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 10;

    public array $backoff = [10, 60, 600, 3600];

    public function __construct(public string $chargeId)
    {
        $this->queue = config('simplestats-client.queue');
    }

    public function handle(StripeChargeResolver $resolver): void
    {
        try {
            $payment = $resolver->resolve($this->chargeId);
        } catch (InvalidRequestException $e) {
            // e.g. a charge of another Stripe account or mode, retrying cannot help
            if ($e->getHttpStatus() === 404) {
                return;
            }

            throw $e;
        }

        if ($payment) {
            SimplestatsClient::trackPayment($payment);
        }
    }
}
