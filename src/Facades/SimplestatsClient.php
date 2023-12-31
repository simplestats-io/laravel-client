<?php

namespace LaracraftTech\SimplestatsClient\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \LaracraftTech\SimplestatsClient\SimplestatsClient
 */
class SimplestatsClient extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \LaracraftTech\SimplestatsClient\SimplestatsClient::class;
    }
}
