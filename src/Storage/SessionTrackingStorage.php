<?php

namespace SimpleStatsIo\LaravelClient\Storage;

use Illuminate\Support\Collection;

class SessionTrackingStorage implements TrackingStorage
{
    public const SESSION_KEY = 'simplestats.tracking';

    public function put(string $identifier, mixed $data): void
    {
        session()->put(self::SESSION_KEY, $data);
    }

    public function get(?string $identifier): Collection
    {
        return session(self::SESSION_KEY) ?? collect();
    }

    public function has(string $identifier): bool
    {
        return ! empty(session(self::SESSION_KEY));
    }
}
