<?php

namespace SimpleStatsIo\LaravelClient\Events;

use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;

class LoginTracked
{
    /**
     * @param  array<string, mixed>  $payload  The payload sent to the SimpleStats API.
     */
    public function __construct(
        public TrackablePerson $user,
        public array $payload,
    ) {}
}
