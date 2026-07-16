<?php

namespace SimpleStatsIo\LaravelClient\Storage;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

class SessionTrackingStorage implements TrackingStorage
{
    public const SESSION_KEY = 'simplestats.tracking';

    public function put(string $identifier, mixed $data): void
    {
        // Store a plain array so the value survives any session serializer and
        // reading it back never requires this package's classes.
        session()->put(self::SESSION_KEY, $data instanceof Arrayable ? $data->toArray() : $data);
    }

    public function get(?string $identifier): Collection
    {
        // Older sessions may still hold a serialized Collection, so always wrap.
        return collect(session(self::SESSION_KEY) ?? []);
    }

    public function has(string $identifier): bool
    {
        return ! empty(session(self::SESSION_KEY));
    }
}
