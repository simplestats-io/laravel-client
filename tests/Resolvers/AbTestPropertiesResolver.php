<?php

namespace SimpleStatsIo\LaravelClient\Tests\Resolvers;

use Illuminate\Http\Request;
use SimpleStatsIo\LaravelClient\Contracts\ResolvesVisitorCustomProperties;

class AbTestPropertiesResolver implements ResolvesVisitorCustomProperties
{
    public function resolve(Request $request): array
    {
        return ['ab_test' => 'B'];
    }
}
