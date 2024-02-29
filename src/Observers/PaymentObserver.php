<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class PaymentObserver
{
    public function created(TrackablePayment $payment)
    {
        if ($payment instanceof TrackablePaymentWithCondition) {
            if ($payment->passTrackingCondition()) {
                SimplestatsClient::trackPayment($payment);
            }
        } else {
            SimplestatsClient::trackPayment($payment);
        }
    }

    public function updated(TrackablePayment $payment)
    {
        if ($payment instanceof TrackablePaymentWithCondition) {
            if ($payment->wasChanged($payment->getTrackingConditionFields()) && $payment->passTrackingCondition()) {
                SimplestatsClient::trackPayment($payment);
            }
        }
    }
}
