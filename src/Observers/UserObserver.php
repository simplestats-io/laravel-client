<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use SimpleStatsIo\LaravelClient\Contracts\TrackableUser;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserObserver
{
    public function created(TrackableUser $user)
    {
        SimplestatsClient::trackUser($user);
    }
}
