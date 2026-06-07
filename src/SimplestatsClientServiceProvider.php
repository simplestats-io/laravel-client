<?php

namespace SimpleStatsIo\LaravelClient;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use SimpleStatsIo\LaravelClient\Events\CustomPropertiesTracked;
use SimpleStatsIo\LaravelClient\Listeners\StoreVisitorProperties;
use SimpleStatsIo\LaravelClient\Listeners\UserLoginListener;
use SimpleStatsIo\LaravelClient\Middleware\CheckTracking;
use SimpleStatsIo\LaravelClient\Observers\PaymentObserver;
use SimpleStatsIo\LaravelClient\Observers\UserObserver;
use SimpleStatsIo\LaravelClient\Services\ApiConnector;
use SimpleStatsIo\LaravelClient\Storage\CacheTrackingStorage;
use SimpleStatsIo\LaravelClient\Storage\SessionTrackingStorage;
use SimpleStatsIo\LaravelClient\Storage\TrackingStorage;
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
        $this->validateConfiguration();
    }

    protected function validateConfiguration(): void
    {
        if ($this->app->runningUnitTests()) {
            return;
        }

        $storage = config('simplestats-client.tracking_storage', 'session');
        $groups = (array) config('simplestats-client.middleware_groups', ['web']);

        if (! in_array($storage, ['session', 'cache'], true)) {
            Log::warning("[simplestats-client] Unknown tracking_storage '{$storage}', falling back to 'session'. Set it to 'session' or 'cache'.");
        }

        if ($storage === 'session' && in_array('api', $groups, true)) {
            Log::warning("[simplestats-client] middleware_groups includes 'api' but tracking_storage is 'session'. API requests typically have no session, so tracking will silently fail. Set tracking_storage to 'cache' for headless/SPA setups.");
        }

        if ($storage === 'cache') {
            $store = config('cache.default');
            $driver = config("cache.stores.{$store}.driver");

            if (in_array($driver, ['array', 'null'], true)) {
                Log::warning("[simplestats-client] tracking_storage is 'cache' but the default cache driver is '{$driver}', which does not persist across requests. Tracking will not work. Use 'redis', 'memcached', or another persistent driver.");
            }
        }
    }

    protected function registerServices(): void
    {
        $this->app->singleton(ApiConnector::class, function ($app) {
            return new ApiConnector(config('simplestats-client.api_url'), config('simplestats-client.api_token'));
        });

        $this->app->bind(TrackingStorage::class, function () {
            return match (config('simplestats-client.tracking_storage')) {
                'cache' => new CacheTrackingStorage,
                default => new SessionTrackingStorage,
            };
        });
    }

    protected function registerEvents(): void
    {
        Event::listen(config('simplestats-client.tracking_types.login.event'), [UserLoginListener::class, 'handle']);
        Event::listen(CustomPropertiesTracked::class, StoreVisitorProperties::class);
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

        $groups = array_unique((array) config('simplestats-client.middleware_groups', ['web']));

        foreach ($groups as $group) {
            $kernel->appendMiddlewareToGroup($group, CheckTracking::class);
        }
    }
}
