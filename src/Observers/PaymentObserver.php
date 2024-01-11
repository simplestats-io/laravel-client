<?php

namespace LaracraftTech\SimplestatsClient\Observers;

use Illuminate\Foundation\Auth\User;
use LaracraftTech\SimplestatsClient\Facades\SimplestatsClient;

class PaymentObserver
{
    public function created($payment)
    {
        SimplestatsClient::trackPayment($payment);
    }
}
