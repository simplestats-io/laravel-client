<?php

namespace SimpleStatsIo\LaravelClient;

use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use SimpleStatsIo\LaravelClient\Commands\SimplestatsClientCommand;
use SimpleStatsIo\LaravelClient\Listeners\UserLoginListener;
use SimpleStatsIo\LaravelClient\Middleware\CheckTrackingCodes;
use SimpleStatsIo\LaravelClient\Observers\PaymentObserver;
use SimpleStatsIo\LaravelClient\Observers\UserObserver;
use SimpleStatsIo\LaravelClient\Services\ApiConnector;
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

    /**
     * @throws BindingResolutionException
     */
    public function boot()
    {
        parent::boot();

        if (!config('simplestats-client.enabled')) {
            return;
        }

        $this->registerApps();
        $this->registerEvents();
        $this->registerObservers();
        $this->registerMiddlewares();
    }

    private function registerApps(): void
    {
        $this->app->singleton(ApiConnector::class, function ($app) {
            return new ApiConnector(config('simplestats-client.api_url'), config('simplestats-client.api_token'));
        });
    }

    private function registerEvents(): void
    {
        Event::listen(config('simplestats-client.tracking_types.login.event'), [UserLoginListener::class, 'handle']);
    }

    private function registerObservers(): void
    {
        config('simplestats-client.tracking_types.user.model')::observe(UserObserver::class);
        config('simplestats-client.tracking_types.payment.model')::observe(PaymentObserver::class);
    }

    /**
     * @throws BindingResolutionException
     */
    private function registerMiddlewares(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', CheckTrackingCodes::class);
    }
}
