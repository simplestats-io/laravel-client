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
