<?php

namespace SimpleStatsIo\LaravelClient\Storage;

use Illuminate\Support\Collection;

interface TrackingStorage
{
    public function put(string $identifier, mixed $data): void;

    /**
     * @return Collection<string, string|null>
     */
    public function get(?string $identifier): Collection;

    public function has(string $identifier): bool;
}
