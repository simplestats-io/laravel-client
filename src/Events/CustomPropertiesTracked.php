<?php

namespace SimpleStatsIo\LaravelClient\Events;

use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;

class CustomPropertiesTracked
{
    /**
     * @param  array<string, scalar|null>  $properties
     * @param  array<string, mixed>  $payload  The payload sent to the SimpleStats API.
     */
    public function __construct(
        public array $properties,
        public TrackablePerson $person,
        public array $payload,
    ) {}
}
