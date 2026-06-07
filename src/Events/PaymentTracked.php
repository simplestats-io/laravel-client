<?php

namespace SimpleStatsIo\LaravelClient\Events;

use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;

class PaymentTracked
{
    /**
     * @param  array<string, mixed>  $payload  The payload sent to the SimpleStats API.
     */
    public function __construct(
        public TrackablePayment $payment,
        public array $payload,
    ) {}
}
