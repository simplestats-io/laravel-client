<?php

if (! function_exists('getBaseURL')) {
    function getBaseURL($url): string
    {
        $parsedUrl = parse_url($url);
        $scheme = isset($parsedUrl['scheme']) ? $parsedUrl['scheme'] . '://' : '';
        $host = $parsedUrl['host'] ?? '';
        return $scheme . $host;
    }
}
