<?php

if (! function_exists('safeDefer')) {
    /**
     * Safe Defer with fallback and swoole compatible.
     */
    function safeDefer(?callable $callback = null, ?string $name = null, bool $always = false): void
    {
        if (function_exists('Illuminate\Support\defer')) {
            Illuminate\Support\defer(...func_get_args());
        } else {
            $callback();
        }
    }
}

if (! function_exists('getSimpleStatsVersion')) {
    function getSimpleStatsVersion(): string
    {
        $composerFile = __DIR__.'/../composer.json';
        $composerData = json_decode(file_get_contents($composerFile), true);

        $packageName = $composerData['name'] ?? 'simplestats-io/laravel-client';

        // in composer v1 \Composer\InstalledVersions is not available by default, but it is so rare, so we return unknown
        return class_exists(\Composer\InstalledVersions::class)
            ? \Composer\InstalledVersions::getVersion($packageName) ?? 'unknown'
            : 'unknown';
    }
}
