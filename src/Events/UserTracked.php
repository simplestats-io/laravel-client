<?php

namespace SimpleStatsIo\LaravelClient\Events;

use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;

class UserTracked
{
    /**
     * @param  array<string, mixed>  $payload  The payload sent to the SimpleStats API.
     */
    public function __construct(
        public TrackablePerson $user,
        public array $payload,
    ) {}
}
