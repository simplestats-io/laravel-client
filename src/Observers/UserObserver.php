<?php

namespace SimpleStatsIo\LaravelClient\Observers;

use Illuminate\Foundation\Auth\User;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

class UserObserver
{
    public function created(User $user)
    {
        SimplestatsClient::trackUser($user);
    }
}
