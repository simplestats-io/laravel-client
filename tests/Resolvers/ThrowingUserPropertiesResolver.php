<?php

namespace SimpleStatsIo\LaravelClient\Tests\Resolvers;

use Exception;
use SimpleStatsIo\LaravelClient\Contracts\ResolvesUserCustomProperties;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;

class ThrowingUserPropertiesResolver implements ResolvesUserCustomProperties
{
    public function resolve(TrackablePerson $user): array
    {
        throw new Exception('resolver failed');
    }
}
