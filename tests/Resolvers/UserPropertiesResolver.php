<?php

namespace SimpleStatsIo\LaravelClient\Tests\Resolvers;

use SimpleStatsIo\LaravelClient\Contracts\ResolvesUserCustomProperties;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;

class UserPropertiesResolver implements ResolvesUserCustomProperties
{
    public function resolve(TrackablePerson $user): array
    {
        return [
            'ab_test' => 'B',
            'company' => 'Acme Inc',
        ];
    }
}
