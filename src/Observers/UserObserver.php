<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use Illuminate\Database\Eloquent\Model;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUserWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserObserver
{
    /**
     * @param  TrackableUser&Model  $user
     * @return void
     */
    public function created(TrackableUser $user)
    {
        if ($user instanceof TrackableUserWithCondition) {
            if ($user->passTrackingCondition()) {
                SimplestatsClient::trackUser($user);
            }
        } else {
            SimplestatsClient::trackUser($user);
        }
    }

    /**
     * @param  TrackableUser&Model  $user
     * @return void
     */
    public function updated(TrackableUser $user)
    {
        if ($user instanceof TrackableUserWithCondition) {
            if ($user->wasChanged($user->watchTrackingFields()) && $user->passTrackingCondition()) {
                // If we use the TrackableUserWithCondition, the first login, before the email is verified
                // will not be counted, cause the email gets verified only after the successful login
                // (after clicking on the verification link in the email). That's why we need to
                // tell the api to also count a login here...
                SimplestatsClient::trackUser($user, true);
            }
        }
    }
}
