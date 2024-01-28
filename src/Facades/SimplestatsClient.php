<?php

namespace SimpleStatsIo\LaravelClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \SimpleStatsIo\LaravelClient\SimplestatsClient
 */
class SimplestatsClient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \SimpleStatsIo\LaravelClient\SimplestatsClient::class;
    }
}
