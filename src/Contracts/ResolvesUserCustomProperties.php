<?php

namespace SimpleStatsIo\LaravelClient\Contracts;

interface ResolvesUserCustomProperties
{
    /**
     * Custom properties for a user that is being tracked right now, e.g.
     * ['subscription' => 'pro', 'company' => 'Acme Inc'].
     *
     * The class configured as custom_properties_resolvers.user is invoked
     * whenever the user is tracked. Keys are the property names (the grouping
     * dimension), values are the current value for this user. Values should
     * be scalar; non-scalar or empty values are ignored on the server. Each
     * (user, name) keeps exactly one current value, so re-tracking with a new
     * value overwrites the old one.
     *
     * @return array<string, scalar|null>
     */
    public function resolve(TrackablePerson $user): array;
}
