<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use Illuminate\Foundation\Auth\User;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class PaymentObserver
{
    public function created($payment)
    {
        SimplestatsClient::trackPayment($payment);
    }
}
