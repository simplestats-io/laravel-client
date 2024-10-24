<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use Illuminate\Database\Eloquent\Model;
use SimpleStatsIo\LaravelClient\Contracts\TrackableIndividual;
use SimpleStatsIo\LaravelClient\Contracts\TrackableIndividualWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserObserver
{
    /**
     * @param  TrackableIndividual&Model  $user
     * @return void
     */
    public function created(TrackableIndividual $user)
    {
        if ($user instanceof TrackableIndividualWithCondition) {
            if ($user->passTrackingCondition()) {
                SimplestatsClient::trackUser($user);
            }
        } else {
            SimplestatsClient::trackUser($user);
        }
    }

    /**
     * @param  TrackableIndividual&Model  $user
     * @return void
     */
    public function updated(TrackableIndividual $user)
    {
        if ($user instanceof TrackableIndividualWithCondition) {
            if ($user->wasChanged($user->watchTrackingFields()) && $user->passTrackingCondition()) {
                // If we use the TrackableIndividualWithCondition, the first login, before the email is verified
                // will not be counted, cause the email gets verified only after the successful login
                // (after clicking on the verification link in the email). That's why we need to
                // tell the api to also count a login here...
                SimplestatsClient::trackUser($user, true);
            }
        }
    }
}
