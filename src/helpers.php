<?php

if (! function_exists('defer')) {
    /**
     * Defer fallback.
     */
    function defer(?callable $callback = null, ?string $name = null, bool $always = false)
    {
        return $callback();
    }
}
