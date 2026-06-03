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
                // When the user meets the tracking condition within their own authenticated session
                // (e.g. clicking the email verification link while already logged in), their sign-in
                // happened before the condition was met and was therefore skipped by UserLoginListener.
                // We recover that skipped login by also counting one here.
                //
                // When the condition is met before the user signs in (e.g. an SSO provider creates an
                // already-verified user and logs them in afterwards), the subsequent Login event records
                // the login, so adding one here would double-count it (see issue #250).
                $addLogin = auth()->user()?->is($user) ?? false;

                SimplestatsClient::trackUser($user, $addLogin);
            }
        }
    }
}
