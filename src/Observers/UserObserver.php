<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use Illuminate\Database\Eloquent\Model;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUserWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserObserver
{
    public function created(Model&TrackableUser $user)
    {
        if ($user instanceof TrackableUserWithCondition) {
            if ($user->passTrackingCondition()) {
                SimplestatsClient::trackUser($user);
            }
        } else {
            SimplestatsClient::trackUser($user);
        }
    }

    public function updated(Model&TrackableUser $user)
    {
        if ($user instanceof TrackableUserWithCondition) {
            if ($user->wasChanged($user->getTrackingConditionFields()) && $user->passTrackingCondition()) {
                SimplestatsClient::trackUser($user);
            }
        }
    }
}
