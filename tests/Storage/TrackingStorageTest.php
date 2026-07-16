<?php

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use SimpleStatsIo\LaravelClient\Storage\CacheTrackingStorage;
use SimpleStatsIo\LaravelClient\Storage\SessionTrackingStorage;

it('returns a collection from session storage when the session holds an array', function () {
    $storage = new SessionTrackingStorage;

    session([SessionTrackingStorage::SESSION_KEY => [
        'source' => 'google',
        'ip' => '1.2.3.4',
    ]]);

    $result = $storage->get('visitor-hash');

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->get('source'))->toBe('google')
        ->and($result->get('ip'))->toBe('1.2.3.4');
});

it('returns an empty collection from session storage when nothing is stored', function () {
    $storage = new SessionTrackingStorage;

    expect($storage->get('visitor-hash'))->toBeInstanceOf(Collection::class)
        ->and($storage->get('visitor-hash'))->toBeEmpty();
});

it('returns a collection from session storage after putting a collection', function () {
    $storage = new SessionTrackingStorage;

    $storage->put('visitor-hash', collect([
        'source' => 'newsletter',
        'page' => '/pricing',
    ]));

    $result = $storage->get('visitor-hash');

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->get('source'))->toBe('newsletter')
        ->and($result->get('page'))->toBe('/pricing');
});

it('returns a collection from cache storage when the cache holds an array', function () {
    $storage = new CacheTrackingStorage;
    $identifier = 'visitor-hash';

    Cache::put(CacheTrackingStorage::CACHE_KEY_PREFIX.$identifier, [
        'source' => 'google',
        'ip' => '1.2.3.4',
    ], now()->endOfDay());

    $result = $storage->get($identifier);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->get('source'))->toBe('google')
        ->and($result->get('ip'))->toBe('1.2.3.4');
});

it('returns an empty collection from cache storage when nothing is stored', function () {
    $storage = new CacheTrackingStorage;

    expect($storage->get('missing'))->toBeInstanceOf(Collection::class)
        ->and($storage->get('missing'))->toBeEmpty();
});

it('returns an empty collection from cache storage when the identifier is empty', function () {
    $storage = new CacheTrackingStorage;

    expect($storage->get(null))->toBeInstanceOf(Collection::class)->toBeEmpty()
        ->and($storage->get(''))->toBeInstanceOf(Collection::class)->toBeEmpty();
});

it('stores tracking data as a plain array in the session', function () {
    $storage = new SessionTrackingStorage;

    $storage->put('visitor-hash', collect(['source' => 'google']));

    expect(session(SessionTrackingStorage::SESSION_KEY))->toBeArray()
        ->toBe(['source' => 'google']);
});

it('stores tracking data as a plain array in the cache', function () {
    $storage = new CacheTrackingStorage;
    $identifier = 'visitor-hash';

    $storage->put($identifier, collect(['source' => 'google']));

    expect(Cache::get(CacheTrackingStorage::CACHE_KEY_PREFIX.$identifier))->toBeArray()
        ->toBe(['source' => 'google']);
});

it('returns a collection from session storage when the session holds a legacy collection', function () {
    session([SessionTrackingStorage::SESSION_KEY => collect(['source' => 'google'])]);

    $result = (new SessionTrackingStorage)->get('visitor-hash');

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->get('source'))->toBe('google');
});

it('returns a collection from cache storage when the cache holds a legacy collection', function () {
    $identifier = 'visitor-hash';
    Cache::put(CacheTrackingStorage::CACHE_KEY_PREFIX.$identifier, collect(['source' => 'google']), now()->endOfDay());

    $result = (new CacheTrackingStorage)->get($identifier);

    expect($result)->toBeInstanceOf(Collection::class)
        ->and($result->get('source'))->toBe('google');
});
