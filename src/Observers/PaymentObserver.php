<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use Illuminate\Database\Eloquent\Model;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class PaymentObserver
{
    public function created(Model&TrackablePayment $payment)
    {
        if ($payment instanceof TrackablePaymentWithCondition) {
            if ($payment->passTrackingCondition()) {
                SimplestatsClient::trackPayment($payment);
            }
        } else {
            SimplestatsClient::trackPayment($payment);
        }
    }

    public function updated(Model&TrackablePayment $payment)
    {
        if ($payment instanceof TrackablePaymentWithCondition) {
            if ($payment->wasChanged($payment->getTrackingConditionFields()) && $payment->passTrackingCondition()) {
                SimplestatsClient::trackPayment($payment);
            }
        }
    }
}
