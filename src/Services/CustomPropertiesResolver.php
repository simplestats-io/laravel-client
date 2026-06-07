<?php

namespace SimpleStatsIo\LaravelClient\Services;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;
use SimpleStatsIo\LaravelClient\Contracts\ResolvesUserCustomProperties;
use SimpleStatsIo\LaravelClient\Contracts\ResolvesVisitorCustomProperties;
use SimpleStatsIo\LaravelClient\Contracts\TrackablePerson;
use Throwable;

/**
 * Invokes the customer's configured custom properties resolvers. Tracking
 * must never break the host app, so a misconfigured (missing contract) or
 * throwing resolver is reported and treated as "no properties".
 */
class CustomPropertiesResolver
{
    /**
     * @return array<string, scalar|null>
     */
    public function forUser(TrackablePerson $user): array
    {
        return $this->resolveSafely(
            config('simplestats-client.custom_properties_resolvers.user'),
            ResolvesUserCustomProperties::class,
            fn (ResolvesUserCustomProperties $resolver) => $resolver->resolve($user),
        );
    }

    /**
     * @return array<string, scalar|null>
     */
    public function forVisitor(Request $request): array
    {
        return $this->resolveSafely(
            config('simplestats-client.custom_properties_resolvers.visitor'),
            ResolvesVisitorCustomProperties::class,
            fn (ResolvesVisitorCustomProperties $resolver) => $resolver->resolve($request),
        );
    }

    /**
     * @param  class-string  $contract
     * @return array<string, scalar|null>
     */
    protected function resolveSafely(?string $resolverClass, string $contract, Closure $resolve): array
    {
        if ($resolverClass === null) {
            return [];
        }

        try {
            $resolver = app($resolverClass);

            if (! $resolver instanceof $contract) {
                throw new InvalidArgumentException(
                    "The configured custom properties resolver [{$resolverClass}] must implement [{$contract}]."
                );
            }

            return $resolve($resolver);
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }
    }
}
