<?php

namespace SimpleStatsIo\LaravelClient\Tests\Resolvers;

use Exception;
use Illuminate\Http\Request;
use SimpleStatsIo\LaravelClient\Contracts\ResolvesVisitorCustomProperties;

class ThrowingVisitorPropertiesResolver implements ResolvesVisitorCustomProperties
{
    public function resolve(Request $request): array
    {
        throw new Exception('resolver failed');
    }
}
