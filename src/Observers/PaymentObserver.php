<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use Illuminate\Database\Eloquent\Model;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePayment;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePaymentWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class PaymentObserver
{
    /**
     * @param  TrackablePayment&Model  $payment
     * @return void
     */
    //    public function creating(TrackablePayment $payment)
    //    {
    //        if ($payment->isFillable('visitor_hash')) {
    //            $payment->visitor_hash = session('simplestats.visitor_hash');
    //        }
    //    }

    /**
     * @param  TrackablePayment&Model  $payment
     * @return void
     */
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

    /**
     * @param  TrackablePayment&Model  $payment
     * @return void
     */
    public function updated(TrackablePayment $payment)
    {
        if ($payment instanceof TrackablePaymentWithCondition) {
            if ($payment->wasChanged($payment->watchTrackingFields()) && $payment->passTrackingCondition()) {
                SimplestatsClient::trackPayment($payment);
            }
        }
    }
}
