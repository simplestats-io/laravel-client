<?php

namespace SimpleStatsIo\LaravelClient\Events;

use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;

class CustomEventTracked
{
    /**
     * @param  array<string, mixed>  $payload  The payload sent to the SimpleStats API.
     */
    public function __construct(
        public string $id,
        public string $name,
        public TrackablePerson $person,
        public array $payload,
    ) {}
}
