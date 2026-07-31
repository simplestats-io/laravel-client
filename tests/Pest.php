<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Http;
use SimpleStatsIo\LaravelClient\Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in(__DIR__);

function flushDeferred(): void
{
    if (class_exists(DeferredCallbackCollection::class)) {
        app(DeferredCallbackCollection::class)->invoke();
    }
}

function assertVisitorTrackedWith(string $field, mixed $expected): void
{
    flushDeferred();

    $apiUrl = config('simplestats-client.api_url');

    Http::assertSent(function ($request) use ($apiUrl, $field, $expected) {
        return $request->url() === $apiUrl.'stats-visitor'
            && ($request->data()[$field] ?? null) === $expected;
    });
}
