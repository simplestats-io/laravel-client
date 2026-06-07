<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

use Illuminate\Http\Request;

interface ResolvesVisitorCustomProperties
{
    /**
     * Custom properties for a visitor that is being tracked right now, e.g.
     * ['ab_test' => 'B'].
     *
     * The class configured as custom_properties_resolvers.visitor is invoked
     * at the moment a new visitor is first tracked. The resolved properties
     * are sent along with the visitor track itself and are inherited by a
     * later user registration of the same visit. Values should be scalar;
     * non-scalar or empty values are ignored on the server.
     *
     * @return array<string, scalar|null>
     */
    public function resolve(Request $request): array;
}
