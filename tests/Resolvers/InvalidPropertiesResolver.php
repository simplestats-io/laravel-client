<?php

namespace SimpleStatsIo\LaravelClient\Tests\Resolvers;

use Illuminate\Http\Request;

/**
 * Misconfiguration fixture: has a resolve() method but does not implement
 * the resolver contract.
 */
class InvalidPropertiesResolver
{
    public function resolve(Request $request): array
    {
        return ['ab_test' => 'B'];
    }
}
