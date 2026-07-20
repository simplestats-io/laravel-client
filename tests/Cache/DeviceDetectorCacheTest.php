<?php

use Illuminate\Support\Facades\Cache;
use SimpleStatsIo\LaravelClient\Cache\DeviceDetectorCache;

it('reads and writes through the configured store', function () {
    $cache = new DeviceDetectorCache('array');

    expect($cache->contains('foo'))->toBeFalse();
    expect($cache->fetch('foo'))->toBeNull();

    $cache->save('foo', ['bar' => 'baz']);

    expect($cache->contains('foo'))->toBeTrue();
    expect($cache->fetch('foo'))->toBe(['bar' => 'baz']);
    expect(Cache::store('array')->get('foo'))->toBe(['bar' => 'baz']);

    $cache->delete('foo');

    expect($cache->contains('foo'))->toBeFalse();
});

it('targets the named store, not the default store', function () {
    $cache = new DeviceDetectorCache('array');

    $cache->save('only-in-array', 'value');

    expect(Cache::store('array')->get('only-in-array'))->toBe('value');
});

it('fails open when the store is unusable instead of throwing', function () {
    $cache = new DeviceDetectorCache('does-not-exist');

    expect(fn () => $cache->contains('foo'))->not->toThrow(Throwable::class);
    expect($cache->contains('foo'))->toBeFalse();
    expect($cache->fetch('foo'))->toBeNull();
    expect($cache->save('foo', 'bar'))->toBeFalse();
    expect($cache->delete('foo'))->toBeFalse();
    expect($cache->flushAll())->toBeFalse();
});
