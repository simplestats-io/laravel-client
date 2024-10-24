<?php

namespace SimpleStatsIo\LaravelClient\Listeners;

use Illuminate\Auth\Events\Login;
use SimpleStatsIo\LaravelClient\Contracts\TrackableIndividual;
use SimpleStatsIo\LaravelClient\Contracts\TrackableIndividualWithCondition;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserLoginListener
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        /** @var TrackableIndividual $user */
        $user = $event->user;

        if ($user instanceof TrackableIndividualWithCondition) {
            if ($user->passTrackingCondition()) {
                SimplestatsClient::trackLogin($user);
            }
        } else {
            SimplestatsClient::trackLogin($user);
        }
    }
}
