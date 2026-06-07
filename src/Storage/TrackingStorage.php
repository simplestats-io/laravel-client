<?php

namespace SimpleStatsIo\LaravelClient\Storage;

use Illuminate\Support\Collection;

interface TrackingStorage
{
    public function put(string $identifier, mixed $data): void;

    /**
     * Attribution values are strings, the inherited visitor properties live
     * under the 'properties' key as a name => value array.
     *
     * @return Collection<string, mixed>
     */
    public function get(?string $identifier): Collection;

    public function has(string $identifier): bool;
}
