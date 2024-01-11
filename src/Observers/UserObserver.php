<?php

namespace LaracraftTech\SimplestatsClient\Observers;

use Illuminate\Foundation\Auth\User;
use LaracraftTech\SimplestatsClient\Facades\SimplestatsClient;

class UserObserver
{
    public function created(User $user)
    {
        SimplestatsClient::trackUser($user);
    }
}
