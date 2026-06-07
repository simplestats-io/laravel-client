<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Exceptions;
use SimpleStatsIo\LaravelClient\Services\CustomPropertiesResolver;
use SimpleStatsIo\LaravelClient\Tests\Models\User;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\AbTestPropertiesResolver;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\InvalidPropertiesResolver;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\ThrowingUserPropertiesResolver;
use SimpleStatsIo\LaravelClient\Tests\Resolvers\UserPropertiesResolver;

it('resolves user properties from the configured resolver', function () {
    config(['simplestats-client.custom_properties_resolvers.user' => UserPropertiesResolver::class]);

    expect(app(CustomPropertiesResolver::class)->forUser(new User))
        ->toBe(['subscription' => 'pro', 'company' => 'Acme Inc']);
});

it('resolves visitor properties from the configured resolver', function () {
    config(['simplestats-client.custom_properties_resolvers.visitor' => AbTestPropertiesResolver::class]);

    expect(app(CustomPropertiesResolver::class)->forVisitor(Request::create('/')))
        ->toBe(['ab_test' => 'B']);
});

it('resolves no properties when no resolver is configured', function () {
    expect(app(CustomPropertiesResolver::class)->forUser(new User))->toBe([])
        ->and(app(CustomPropertiesResolver::class)->forVisitor(Request::create('/')))->toBe([]);
});

it('reports a resolver missing the contract and resolves no properties', function () {
    Exceptions::fake();

    config(['simplestats-client.custom_properties_resolvers.user' => InvalidPropertiesResolver::class]);

    expect(app(CustomPropertiesResolver::class)->forUser(new User))->toBe([]);

    Exceptions::assertReported(InvalidArgumentException::class);
});

it('reports a throwing resolver and resolves no properties', function () {
    Exceptions::fake();

    config(['simplestats-client.custom_properties_resolvers.user' => ThrowingUserPropertiesResolver::class]);

    expect(app(CustomPropertiesResolver::class)->forUser(new User))->toBe([]);

    Exceptions::assertReported(fn (Exception $exception) => $exception->getMessage() === 'resolver failed');
});
