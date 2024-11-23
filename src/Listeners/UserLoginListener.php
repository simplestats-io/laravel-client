<?php

namespace SimpleStatsIo\LaravelClient\Listeners;

use Illuminate\Auth\Events\Login;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePersonWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserLoginListener
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        /** @var TrackablePerson $user */
        $user = $event->user;

        if ($user instanceof TrackablePersonWithCondition) {
            if ($user->passTrackingCondition()) {
                SimplestatsClient::trackLogin($user);
            }
        } else {
            SimplestatsClient::trackLogin($user);
        }
    }
}
