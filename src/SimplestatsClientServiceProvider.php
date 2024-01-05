<?php

namespace LaracraftTech\SimplestatsClient;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Event;
use LaracraftTech\SimplestatsClient\Listeners\UserLoginListener;
use LaracraftTech\SimplestatsClient\Middleware\CheckTrackingCodes;
use LaracraftTech\SimplestatsClient\Observers\UserObserver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use LaracraftTech\SimplestatsClient\Commands\SimplestatsClientCommand;
use Illuminate\Contracts\Http\Kernel;

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

    /**
     * @throws BindingResolutionException
     */
    public function boot()
    {
        parent::boot();

        $this->registerApps();
        $this->registerEvents();
        $this->registerObservers();
        $this->registerMiddlewares();
    }

    /**
     * @return void
     */
    private function registerApps(): void
    {
        $this->app->singleton(SimplestatsClient::class, function ($app) {
            return new SimplestatsClient(config('simplestats-client.api_url'), config('simplestats-client.api_token'));
        });
    }

    private function registerEvents()
    {
        Event::listen(Login::class, [UserLoginListener::class, 'handle']);
    }

    /**
     * @return void
     */
    private function registerObservers(): void
    {
        User::observe(UserObserver::class);
    }

    /**
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    private function registerMiddlewares(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', CheckTrackingCodes::class);
    }
}
