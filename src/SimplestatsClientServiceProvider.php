<?php

namespace LaracraftTech\SimplestatsClient;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel;
use LaracraftTech\SimplestatsClient\Commands\SimplestatsClientCommand;
use LaracraftTech\SimplestatsClient\Middleware\CheckTrackingCodes;
use LaracraftTech\SimplestatsClient\Observers\UserObserver;
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

    public function boot()
    {
        parent::boot();

        $this->app->singleton(SimplestatsClient::class, function ($app) {
            return new SimplestatsClient(config('simplestats-client.api_url'), config('simplestats-client.api_token'));
        });

        User::observe(UserObserver::class);

        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', CheckTrackingCodes::class);
    }
}
