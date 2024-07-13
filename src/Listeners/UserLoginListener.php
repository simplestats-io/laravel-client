<?php

namespace SimpleStatsIo\LaravelClient\Listeners;

use Illuminate\Auth\Events\Login;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;
use SimpleStatsIo\LaravelClient\Contracts\TrackableUserWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserLoginListener
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        /** @var TrackableUser $user */
        $user = $event->user;

        if ($user instanceof TrackableUserWithCondition) {
            if ($user->passTrackingCondition()) {
                SimplestatsClient::trackLogin($user);
            }
        } else {
            SimplestatsClient::trackLogin($user);
        }
    }
}
