<?php

namespace SimpleStatsIo\LaravelClient;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use SimpleStatsIo\LaravelClient\Listeners\UserLoginListener;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;
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
            ->hasMigration('add_visitor_hash_to_payments_table');
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot()
    {
        parent::boot();

        if (! config('simplestats-client.enabled')) {
            return;
        }

        $this->registerServices();
        $this->registerEvents();
        $this->registerObservers();
        $this->registerMiddlewares();
    }

    protected function registerServices(): void
    {
        $this->app->singleton(ApiConnector::class, function ($app) {
            return new ApiConnector(config('simplestats-client.api_url'), config('simplestats-client.api_token'));
        });
    }

    protected function registerEvents(): void
    {
        Event::listen(config('simplestats-client.tracking_types.login.event'), [UserLoginListener::class, 'handle']);
    }

    protected function registerObservers(): void
    {
        $userModel = config('simplestats-client.tracking_types.user.model');
        if ($userModel && class_exists($userModel)) {
            $userModel::observe(UserObserver::class);
        }

        $paymentModel = config('simplestats-client.tracking_types.payment.model');
        if ($paymentModel && class_exists($paymentModel)) {
            $paymentModel::observe(PaymentObserver::class);
        }
    }

    /**
     * @throws BindingResolutionException
     */
    protected function registerMiddlewares(): void
    {
        $kernel = $this->app->make(Kernel::class);
        $kernel->appendMiddlewareToGroup('web', CheckTracking::class);
    }
}
