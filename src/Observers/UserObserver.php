<?php

namespace LaracraftTech\SimplestatsClient\Observers;

use App\Models\User;
use LaracraftTech\SimplestatsClient\Facades\SimplestatsClient;

class UserObserver
{
    /**
     * We can't typehint here, cause it may be any kind of user model...
     *
     * @return void
     */
    public function created(User $user)
    {
        SimplestatsClient::trackRegistration($user);
    }
}
