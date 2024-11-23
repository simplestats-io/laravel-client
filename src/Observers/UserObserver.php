<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use Illuminate\Database\Eloquent\Model;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePersonWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserObserver
{
    /**
     * @param  TrackablePerson&Model  $user
     * @return void
     */
    public function created(TrackablePerson $user)
    {
        if ($user instanceof TrackablePersonWithCondition) {
            if ($user->passTrackingCondition()) {
                SimplestatsClient::trackUser($user);
            }
        } else {
            SimplestatsClient::trackUser($user);
        }
    }

    /**
     * @param  TrackablePerson&Model  $user
     * @return void
     */
    public function updated(TrackablePerson $user)
    {
        if ($user instanceof TrackablePersonWithCondition) {
            if ($user->wasChanged($user->watchTrackingFields()) && $user->passTrackingCondition()) {
                // If we use the TrackablePersonWithCondition, the first login, before the email is verified
                // will not be counted, cause the email gets verified only after the successful login
                // (after clicking on the verification link in the email). That's why we need to
                // tell the api to also count a login here...
                SimplestatsClient::trackUser($user, true);
            }
        }
    }
}
