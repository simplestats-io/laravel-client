<?php

namespace LaracraftTech\SimplestatsClient\Tests;

use Illuminate\Database\Eloquent\Factories\Factory;
use LaracraftTech\SimplestatsClient\SimplestatsClientServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => 'LaracraftTech\\SimplestatsClient\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            SimplestatsClientServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        config()->set('database.default', 'testing');

        /*
        $migration = include __DIR__.'/../database/migrations/create_simplestats-client_table.php.stub';
        $migration->up();
        */
    }
}
