<?php

namespace SimpleStatsIo\LaravelClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void trackVisitor(\SimpleStatsIo\LaravelClient\Contracts\TrackablePerson $visitor)
 * @method static void trackLogin(\Illuminate\Contracts\Auth\Authenticatable $user)
 * @method static void trackUser(\SimpleStatsIo\LaravelClient\Contracts\TrackablePerson $user, bool $addLogin = false)
 * @method static void trackPayment(\SimpleStatsIo\LaravelClient\Contracts\TrackablePayment $payment)
 * @method static void trackCustomEvent(string $id, string $name, \SimpleStatsIo\LaravelClient\Contracts\TrackablePerson $user)
 *
 * @see \SimpleStatsIo\LaravelClient\SimplestatsClient
 */
class SimplestatsClient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \SimpleStatsIo\LaravelClient\SimplestatsClient::class;
    }
}
