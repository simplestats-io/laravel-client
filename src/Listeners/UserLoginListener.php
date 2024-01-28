<?php

namespace SimpleStatsIo\LaravelClient\Listeners;

use Illuminate\Auth\Events\Login;
use SimpleStatsIo\LaravelClient\Facades\SimplestatsClient;

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
