<?php

namespace LaracraftTech\SimplestatsClient;

use LaracraftTech\SimplestatsClient\Commands\SimplestatsClientCommand;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class SimplestatsClientServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('simplestats-client')
            ->hasConfigFile()
            ->hasCommand(SimplestatsClientCommand::class);
    }
}
