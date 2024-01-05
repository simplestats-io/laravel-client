<?php

namespace LaracraftTech\SimplestatsClient\Listeners;

use Illuminate\Auth\Events\Login;
use LaracraftTech\SimplestatsClient\Facades\SimplestatsClient;

class UserLoginListener
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        SimplestatsClient::trackLogin($event->user);
    }
}
